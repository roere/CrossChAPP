#!/usr/bin/env python3
import json,time,urllib.request
BASE='http://127.0.0.1:9516'
def request(method,path,payload=None):
    req=urllib.request.Request(BASE+path,data=None if payload is None else json.dumps(payload).encode(),method=method,headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(req) as response:return json.loads(response.read())['value']
session=request('POST','/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome','goog:chromeOptions':{'args':['--headless','--no-sandbox','--disable-gpu','--window-size=1440,1000']},'goog:loggingPrefs':{'browser':'ALL','performance':'ALL'}}}})['sessionId']
def script(source):return request('POST',f'/session/{session}/execute/sync',{'script':source,'args':[]})
def async_script(source):return request('POST',f'/session/{session}/execute/async',{'script':source,'args':[]})
def network():
    entries=request('POST',f'/session/{session}/log',{'type':'performance'}); result=[]
    for entry in entries:
        message=json.loads(entry['message'])['message']
        if message['method']=='Network.requestWillBeSent':result.append((message['params']['request']['method'],message['params']['request']['url']))
    return result
try:
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.3)
    script("const f=document.querySelector('#login-form');f.login.value='representation-a@example.invalid';f.password.value='representation-test-123';f.requestSubmit();");time.sleep(.7)
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung-finden'});time.sleep(.7);initial_network=network()
    assert len([item for item in initial_network if '/api/representation/find.php' in item[1]])==1 and not [item for item in initial_network if 'bni.de' in item[1] or 'bni-' in item[1]],initial_network
    assets=script("return [...document.scripts].map(x=>x.src).filter(x=>/date-picker|representation-find/.test(x));")
    assert len(assets)==2 and all('?v=' in item for item in assets),assets
    assert script("const ids=[...document.querySelectorAll('[id]')].map(x=>x.id);return new Set(ids).size===ids.length;")
    chapter_meta=script("const title=document.querySelector('.page-intro-title'),meta=document.querySelector('#representation-home-chapter'),requests=document.querySelector('.representation-requests');return {text:meta.textContent,hidden:meta.hidden,afterTitle:title.nextElementSibling===meta,beforeRequests:meta.nextElementSibling===requests,orgId:/orgId/i.test(meta.textContent)};")
    assert not chapter_meta['hidden'] and chapter_meta['afterTitle'] and chapter_meta['beforeRequests'] and chapter_meta['text'].startswith('Heimatchapter: ') and not chapter_meta['orgId'],chapter_meta
    request('POST',f'/session/{session}/window/rect',{'width':390,'height':844});mobile=script("const m=document.querySelector('#representation-home-chapter').getBoundingClientRect();return {right:m.right,viewport:innerWidth,scroll:document.documentElement.scrollWidth,client:document.documentElement.clientWidth};");assert mobile['right']<=mobile['viewport'] and mobile['scroll']<=mobile['client'],mobile;request('POST',f'/session/{session}/window/rect',{'width':1440,'height':1000})
    chosen=script("const d=new Date();while(d.getDay()!==5)d.setDate(d.getDate()+1);d.setDate(d.getDate()+14);const iso=d.toISOString().slice(0,10),p=iso.split('-'),i=document.querySelector('#representation-request-date');i.value=p[2]+'.'+p[1]+'.'+p[0];i.dispatchEvent(new Event('input',{bubbles:true}));return iso;")
    time.sleep(.9);find_network=network()
    request_posts=[item for item in find_network if item[0]=='POST' and '/api/representation/requests.php' in item[1]]
    offer_posts=[item for item in find_network if item[0]=='POST' and '/api/representation/offers.php' in item[1]]
    assert len(request_posts)==1 and len(offer_posts)==0,find_network
    find_payload=async_script("const done=arguments[arguments.length-1];fetch('/api/representation/find.php').then(r=>r.json()).then(done);")
    home_name=find_payload['chapter']['chapterName']
    assert chapter_meta['text']==f'Heimatchapter: {home_name}',(chapter_meta,home_name)
    assert len(find_payload['requests'])==1 and find_payload['requests'][0]['requestDate']==chosen
    assert find_payload['datedOffers']==[] and find_payload['allDatesOffers']==[] and find_payload['offers']==[],find_payload
    display=script("const always=document.querySelector('#all-dates-representations-section');return {chips:document.querySelectorAll('#representation-request-chips .date-chip').length,dateGroups:document.querySelectorAll('#dated-representations .representation-date-group').length,providers:document.querySelectorAll('#dated-representations .representation-provider-card').length,overview:document.querySelectorAll('#representation-offers-overview .representation-offer-card').length,empty:document.querySelector('#dated-representations').textContent.includes('Aktuell sind keine Angebote hinterlegt.'),overviewEmpty:document.querySelector('#representation-offers-overview').textContent.includes('Aktuell sind keine Angebote hinterlegt.'),alwaysHidden:always.hidden,alwaysVisible:always.getClientRects().length>0,alwaysText:always.textContent.includes('Aktuell bietet sich niemand pauschal')};")
    assert display=={'chips':1,'dateGroups':0,'providers':0,'overview':0,'empty':True,'overviewEmpty':True,'alwaysHidden':True,'alwaysVisible':False,'alwaysText':False},display

    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung'});time.sleep(.8);network()
    assert script("const ids=[...document.querySelectorAll('[id]')].map(x=>x.id);return new Set(ids).size===ids.length;")
    script("const i=document.querySelector('#representation-date');i.value='31.12.2099';i.dispatchEvent(new Event('input',{bubbles:true}));");time.sleep(.3)
    selection_network=network()
    assert not [item for item in selection_network if item[0]=='POST' and '/api/representation/' in item[1]],selection_network
    script("document.querySelector('#representation-list input[data-org-id]:not(:disabled)').click();document.querySelector('#save-representation-offer').click();");time.sleep(.8)
    save_network=network();request_posts=[item for item in save_network if item[0]=='POST' and '/api/representation/requests.php' in item[1]];offer_posts=[item for item in save_network if item[0]=='POST' and '/api/representation/offers.php' in item[1]]
    assert len(offer_posts)==1 and len(request_posts)==0,save_network
    script("document.querySelector('#logout-button').click()");time.sleep(.4);request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.3)
    script("const f=document.querySelector('#login-form');f.login.value='representation-b@example.invalid';f.password.value='representation-test-123';f.requestSubmit();");time.sleep(.7)
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung'});time.sleep(.8)
    home=json.dumps(home_name);date=json.dumps('.'.join(reversed(chosen.split('-'))))
    selected=script(f"const row=[...document.querySelectorAll('#representation-list tr')].find(x=>x.textContent.includes({home})),i=document.querySelector('#representation-date');if(row)row.querySelector('input[data-org-id]').click();i.value={date};i.dispatchEvent(new Event('input',{{bubbles:true}}));return !!row;")
    assert selected,home_name
    script("document.querySelector('#save-representation-offer').click()");time.sleep(.8);script("document.querySelector('#logout-button').click()");time.sleep(.4);request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.3)
    script("const f=document.querySelector('#login-form');f.login.value='representation-a@example.invalid';f.password.value='representation-test-123';f.requestSubmit();");time.sleep(.7);request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung-finden'});time.sleep(.8)
    final_view=script("const group=[...document.querySelectorAll('#dated-representations .representation-date-group')].find(x=>x.textContent.includes('"+chosen.split('-')[2]+'.'+chosen.split('-')[1]+'.'+chosen.split('-')[0]+"'));return {providers:group?.querySelectorAll('.representation-provider-card').length||0,groupText:group?.textContent||'',overview:document.querySelector('#representation-offers-overview').textContent,ownName:document.querySelector('#representation-offers-overview').textContent.includes('Anna A.')};")
    assert final_view['providers']==1 and 'Bernd B.' in final_view['groupText'] and 'Bernd B.' in final_view['overview'] and not final_view['ownName'],final_view
    script("document.querySelector('#logout-button').click()");time.sleep(.4);request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung-finden'});time.sleep(.4)
    anonymous=script("return {meta:!!document.querySelector('#representation-home-chapter'),hint:document.body.textContent.includes('musst du angemeldet sein')};")
    assert not anonymous['meta'] and anonymous['hint'],anonymous
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.3);script("const f=document.querySelector('#login-form');f.login.value='representation-c@example.invalid';f.password.value='representation-test-123';f.requestSubmit();");time.sleep(.7);request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung-finden'});time.sleep(.4)
    no_home=script("return {meta:!!document.querySelector('#representation-home-chapter'),hint:document.body.textContent.includes('noch kein Heimatchapter hinterlegt')};")
    assert not no_home['meta'] and no_home['hint'],no_home
    logs=request('POST',f'/session/{session}/log',{'type':'browser'});assert not [item for item in logs if item.get('level')=='SEVERE'],logs
    print(json.dumps({'requestDate':chosen,'findPosts':len(request_posts)+1,'findOfferPosts':0,'selectionPosts':0,'saveOfferPosts':len(offer_posts),'findDisplayWithoutOffer':display,'findDisplayWithForeignOffer':final_view},ensure_ascii=False))
    print('PASS representation dataflow: Requests und Angebote bleiben in Browser, Network und Find-Darstellung getrennt')
finally:request('DELETE',f'/session/{session}')
