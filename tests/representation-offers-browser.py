#!/usr/bin/env python3
import json, os, time, urllib.request
BASE='http://127.0.0.1:9516'; CHAPTER_IDS=[int(value) for value in os.environ['CHAPTER_IDS'].split(',')]
def request(method,path,payload=None):
    req=urllib.request.Request(BASE+path,data=None if payload is None else json.dumps(payload).encode(),method=method,headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(req) as response:return json.loads(response.read())['value']
session=request('POST','/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome','goog:chromeOptions':{'args':['--headless','--no-sandbox','--disable-gpu','--window-size=1440,1000']},'goog:loggingPrefs':{'browser':'ALL','performance':'ALL'}}}})['sessionId']
def script(source):return request('POST',f'/session/{session}/execute/sync',{'script':source,'args':[]})
def login(email):
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.3)
    script(f"const f=document.querySelector('#login-form');f.login.value='{email}';f.password.value='representation-test-123';f.requestSubmit();");time.sleep(.6)
try:
    login('representation-c@example.invalid'); request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung-finden'});time.sleep(.4)
    request('POST',f'/session/{session}/log',{'type':'performance'})
    no_home=script("return {hint:document.body.textContent.includes('noch kein Heimatchapter hinterlegt'),list:!!document.querySelector('#available-representations')};")
    assert no_home['hint'] and not no_home['list']
    assert not [entry for entry in request('POST',f'/session/{session}/log',{'type':'performance'}) if '/api/representation/find.php' in entry.get('message','')]
    script("document.querySelector('#logout-button').click()");time.sleep(.4)
    login('representation-b@example.invalid'); request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung'});time.sleep(.8)
    setup=script("const rows=[...document.querySelectorAll('#representation-list input[data-org-id]')],own=rows.find(x=>x.disabled);return {own:Number(own.dataset.orgId),ownMarked:own.closest('tr').textContent.includes('Heimatchapter'),redundant:!!document.querySelector('.representation-save-panel h2')};")
    assert setup['ownMarked'] and not setup['redundant']
    own_rejected=script(f"""const token=document.querySelector('meta[name="csrf-token"]').content;return fetch('/api/representation/offers.php',{{method:'POST',headers:{{'Content-Type':'application/json','X-CSRF-Token':token}},body:JSON.stringify({{orgIds:[{CHAPTER_IDS[0]},{setup['own']}],allDates:true,dates:[]}})}}).then(async r=>({{status:r.status,payload:await r.json()}}));""")
    assert own_rejected['status']==400 and 'eigenes Chapter' in own_rejected['payload']['error']
    assert script("return document.querySelectorAll('#representation-own-list .representation-offer-card').length;")==0
    ids=json.dumps(CHAPTER_IDS)
    script(f"for(const id of {ids})document.querySelector('input[data-org-id=\"'+id+'\"]').click();document.querySelector('#representation-all-dates').click();document.querySelector('#save-representation-offer').click();");time.sleep(.7)
    saved=script("return {message:document.querySelector('#representation-save-message').textContent,cards:[...document.querySelectorAll('#representation-own-list .representation-offer-card')].map(x=>({heading:x.querySelector('h3').textContent,badges:x.querySelectorAll('.date-chip').length}))};")
    assert saved['message']=='3 Vertretungsangebote gespeichert.' and len(saved['cards'])==3 and all(x['badges']==1 for x in saved['cards']),saved
    duplicate=script(f"""const token=document.querySelector('meta[name="csrf-token"]').content;return fetch('/api/representation/offers.php',{{method:'POST',headers:{{'Content-Type':'application/json','X-CSRF-Token':token}},body:JSON.stringify({{orgIds:[{CHAPTER_IDS[0]}],allDates:true,dates:[]}})}}).then(async r=>({{status:r.status,payload:await r.json()}}));""")
    assert duplicate['status']==409 and 'identisches' in duplicate['payload']['error']
    request('POST',f'/session/{session}/log',{'type':'performance'})
    dialog=script("document.querySelector('#representation-own-list button[data-delete-offer]').click();const d=document.querySelector('#delete-representation-dialog');return {open:d.open,title:d.querySelector('h2').textContent,aria:d.getAttribute('aria-modal')};")
    assert dialog['open'] and dialog['title']=='Vertretungsangebot löschen' and dialog['aria']=='true'
    script("document.querySelector('#cancel-delete-representation').click()");time.sleep(.2)
    assert script("return !document.querySelector('#delete-representation-dialog').open&&document.querySelectorAll('#representation-own-list .representation-offer-card').length===3;")
    cancel_network=request('POST',f'/session/{session}/log',{'type':'performance'});assert not [x for x in cancel_network if '/api/representation/offers.php' in x.get('message','')]
    script("document.querySelector('#representation-own-list button[data-delete-offer]').click();document.querySelector('#delete-representation-dialog').dispatchEvent(new Event('cancel',{cancelable:true}));")
    assert not script("return document.querySelector('#delete-representation-dialog').open;")
    for expected in [2,1,0]:
        script("document.querySelector('#representation-own-list button[data-delete-offer]').click();document.querySelector('#confirm-delete-representation').click();");time.sleep(.5)
        assert script("return document.querySelectorAll('#representation-own-list .representation-offer-card').length;")==expected
    for chapter_id in CHAPTER_IDS[:2]:script(f"document.querySelector('input[data-org-id=\"{chapter_id}\"]').click();")
    for date in ['2099-08-21','2099-08-28']:script(f"const i=document.querySelector('#representation-date');i.value='{date}';document.querySelector('#add-representation-date').click();")
    script("document.querySelector('#save-representation-offer').click()");time.sleep(.7)
    dated=script("return [...document.querySelectorAll('#representation-own-list .representation-offer-card')].map(x=>({heading:x.querySelector('h3').textContent,dates:x.querySelectorAll('.date-chip').length}));")
    assert len(dated)==2 and all(x['dates']==2 for x in dated),dated
    script("document.querySelector('#logout-button').click()");time.sleep(.4);login('representation-a@example.invalid')
    assert 'Vertretung finden' in script("return [...document.querySelectorAll('nav a')].map(x=>x.textContent.trim());")
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung-finden'});time.sleep(.7)
    found=script("return {cards:document.querySelectorAll('.representation-offer-card').length,text:document.querySelector('#available-representations').textContent,email:document.body.textContent.includes('representation-b@example.invalid')};")
    assert found['cards']==1 and 'Bernd B.' in found['text'] and not found['email'],found
    logs=request('POST',f'/session/{session}/log',{'type':'browser'});assert not [x for x in logs if x.get('level')=='SEVERE' and '/api/representation/offers.php' not in x.get('message','')],logs
    print('PASS Chromium: 3 Einzelangebote, Dublettenschutz, eigener Löschdialog, 2 Terminangebote und Vertretung finden')
finally:request('DELETE',f'/session/{session}')
