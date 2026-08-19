#!/usr/bin/env python3
import json, time, urllib.request

BASE='http://127.0.0.1:9517'
def request(method,path,payload=None):
    req=urllib.request.Request(BASE+path,data=None if payload is None else json.dumps(payload).encode(),method=method,headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(req) as response:return json.loads(response.read())['value']

session=request('POST','/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome','goog:chromeOptions':{'args':['--headless','--no-sandbox','--disable-gpu','--window-size=1440,1000']},'goog:loggingPrefs':{'browser':'ALL'}}}})['sessionId']
def script(source):return request('POST',f'/session/{session}/execute/sync',{'script':source,'args':[]})
try:
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.4)
    script("const f=document.querySelector('#login-form');f.login.value='admin';f.password.value='admin';f.requestSubmit();");time.sleep(.8)
    assert 'view=admin' in script('return location.href'), 'Lokaler Entwicklungsadmin ist nicht im dokumentierten Zustand admin/admin.'
    panel=script("const p=document.querySelector('#invitations-panel'),misc=document.querySelector('#misc-panel');return {exists:!!p,closed:!p.open,before:!!(p.compareDocumentPosition(misc)&Node.DOCUMENT_POSITION_FOLLOWING)};")
    assert all(panel.values()),panel
    script("const p=document.querySelector('#invitations-panel');p.open=true;p.dispatchEvent(new Event('toggle'));");time.sleep(.6)
    ui=script("const f=document.querySelector('#invitation-form'),t=document.querySelector('#invitation-template-form'),s=document.querySelector('#invitation-search');s.value='Königsforst';s.dispatchEvent(new Event('input',{bubbles:true}));const row=document.querySelector('#invitation-chapter-results .chapter-picker-item'),text=row?.textContent||'';row?.querySelector('input').click();const selected=document.querySelector('#invitation-selected-chapter').textContent,org=f.elements.home_chapter_org_id.value;s.value='Köln';s.dispatchEvent(new Event('input',{bubbles:true}));const cologne=document.querySelectorAll('#invitation-chapter-results input[type=radio]').length;s.value='';const country=document.querySelector('#invitation-country');country.value='AT';country.dispatchEvent(new Event('input',{bubbles:true}));const countryRows=[...document.querySelectorAll('#invitation-chapter-results small')],austria=countryRows.length>0&&countryRows.every(x=>x.textContent.includes('Österreich'));return {required:['first_name','last_name','email','home_chapter_org_id'].every(n=>f.elements[n].required),chapters:text?1:0,row:text,selected,org,cologne,austria,subject:t.elements.subject.value,body:t.elements.body.value,button:f.querySelector('button[type=submit]').getBoundingClientRect().width,form:f.getBoundingClientRect().width,dialog:!!document.querySelector('#cancel-invitation-dialog')};")
    assert ui['required'] and ui['chapters']==1 and 'Königsforst' in ui['row'] and 'Rösrath' in ui['row'] and 'Bergisches Land' in ui['row'] and 'Deutschland' in ui['row'] and 'Königsforst' in ui['selected'] and ui['org']=='44628' and ui['cologne']>0 and ui['austria'] and ui['subject'] and '{{invitation_link}}' in ui['body'] and ui['button']<ui['form']*.6 and ui['dialog'],ui
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?invite=ungueltig'});time.sleep(.4)
    assert 'Dieser Einladungslink ist ungültig.' in script('return document.body.textContent')
    logs=request('POST',f'/session/{session}/log',{'type':'browser'});assert not [x for x in logs if x.get('level')=='SEVERE'],logs
    print('PASS Chromium: Einladungsbereich, Filterauswahl, Vorlage, Buttongröße, Widerrufdialog und ungültiger Token')
finally:
    request('DELETE',f'/session/{session}')
