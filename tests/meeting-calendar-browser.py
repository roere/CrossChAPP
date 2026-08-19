#!/usr/bin/env python3
import json,time,urllib.request
BASE='http://127.0.0.1:9516'
def request(method,path,payload=None):
    req=urllib.request.Request(BASE+path,data=None if payload is None else json.dumps(payload).encode(),method=method,headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(req) as response:return json.loads(response.read())['value']
session=request('POST','/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome','goog:chromeOptions':{'args':['--headless','--no-sandbox','--disable-gpu','--window-size=1440,1000']},'goog:loggingPrefs':{'browser':'ALL','performance':'ALL'}}}})['sessionId']
def script(source):return request('POST',f'/session/{session}/execute/sync',{'script':source,'args':[]})
def async_script(source):return request('POST',f'/session/{session}/execute/async',{'script':source,'args':[]})
try:
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.3)
    script("const f=document.querySelector('#login-form');f.login.value='representation-a@example.invalid';f.password.value='representation-test-123';f.requestSubmit();");time.sleep(.7)
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung-finden'});time.sleep(.8)
    initial=script("const i=document.querySelector('#representation-request-date'),t=document.querySelector('#representation-request-picker [data-date-picker-trigger]');return {native:i.type,placeholder:i.placeholder,day:document.querySelector('#representation-request-day-hint').textContent,disabled:t.disabled};")
    assert initial['native']=='text' and initial['placeholder']=='TT.MM.JJJJ' and 'freitags' in initial['day'] and not initial['disabled'],initial
    find_style=script("const e=document.querySelector('#representation-request-date'),s=getComputedStyle(e),r=e.getBoundingClientRect();return {height:r.height,font:s.fontFamily+'|'+s.fontSize+'|'+s.fontWeight,padding:s.padding,border:s.borderWidth+'|'+s.borderStyle+'|'+s.borderColor};")
    script("document.querySelector('#representation-request-picker [data-date-picker-trigger]').click()");time.sleep(.2)
    grid=script("const root=document.querySelector('#representation-request-picker'),buttons=[...root.querySelectorAll('[data-date-picker-calendar] button[data-date]')],day=b=>new Date(b.dataset.date+'T12:00:00Z').getUTCDay();return {open:!root.querySelector('[data-date-picker-calendar]').hidden,fridays:buttons.filter(b=>day(b)===5&&!b.disabled).length,invalidEnabled:buttons.filter(b=>day(b)!==5&&!b.disabled).length,pastFridayDisabled:buttons.some(b=>day(b)===5&&b.dataset.date<(new Date().toISOString().slice(0,10))&&b.disabled),aria:buttons.every(b=>b.hasAttribute('aria-disabled')),target:buttons.find(b=>day(b)===5&&!b.disabled)?.dataset.date};")
    assert grid['open'] and grid['fridays']>0 and grid['invalidEnabled']==0 and grid['pastFridayDisabled'] and grid['aria'] and grid['target'],grid
    chosen=grid['target'];script(f"document.querySelector('button[data-date=\"{chosen}\"]').click()");time.sleep(.9)
    assert chosen in script("return document.querySelector('#representation-request-chips').textContent") or 'Vertretungsgesuch gespeichert' in script("return document.querySelector('#representation-request-message').textContent")
    request('POST',f'/session/{session}/refresh',{});time.sleep(.7)
    assert script("return document.querySelectorAll('#representation-request-chips .date-chip').length")>=1
    manual_friday=script("const existing=document.querySelector('#representation-request-chips .date-chip').textContent,d=new Date();while(d.getUTCDay()!==5)d.setUTCDate(d.getUTCDate()+1);d.setUTCDate(d.getUTCDate()+7);const iso=d.toISOString().slice(0,10),p=iso.split('-'),i=document.querySelector('#representation-request-date');i.value=p[2]+'.'+p[1]+'.'+p[0];i.dispatchEvent(new Event('input',{bubbles:true}));return iso;")
    time.sleep(.8)
    assert script("return document.querySelectorAll('#representation-request-chips .date-chip').length")>=2,manual_friday
    manual=script("const i=document.querySelector('#representation-request-date'),d=new Date();while(d.getDay()!==3)d.setDate(d.getDate()+1);i.value=String(d.getDate()).padStart(2,'0')+'.'+String(d.getMonth()+1).padStart(2,'0')+'.'+d.getFullYear();i.dispatchEvent(new Event('input',{bubbles:true}));return {invalid:i.getAttribute('aria-invalid'),error:document.querySelector('#representation-request-date-error').textContent};")
    assert manual['invalid']=='true' and 'Mittwoch' not in manual['error'] and 'Freitag' in manual['error'],manual
    bad=async_script("const done=arguments[arguments.length-1],d=new Date();while(d.getDay()!==3)d.setDate(d.getDate()+1);fetch('/api/representation/requests.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({request_date:d.toISOString().slice(0,10)})}).then(async r=>done({status:r.status,body:await r.json()}));")
    assert bad['status']==400,bad
    script("document.querySelector('#representation-request-picker [data-date-picker-trigger]').click();document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));")
    assert script("const r=document.querySelector('#representation-request-picker');return r.querySelector('[data-date-picker-calendar]').hidden&&document.activeElement===r.querySelector('[data-date-picker-trigger]')")
    request('POST',f'/session/{session}/window/rect',{'width':390,'height':844});script("document.querySelector('#representation-request-picker [data-date-picker-trigger]').click()");time.sleep(.2)
    mobile=script("const r=document.querySelector('#representation-request-picker [data-date-picker-calendar]').getBoundingClientRect();return {left:r.left,right:r.right,width:r.width,innerWidth,scrollWidth:document.documentElement.scrollWidth,clientWidth:document.documentElement.clientWidth};")
    assert mobile['left']>=0 and mobile['right']<=mobile['innerWidth'] and mobile['scrollWidth']<=mobile['clientWidth'],mobile
    request('POST',f'/session/{session}/window/rect',{'width':1440,'height':1000});request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung'});time.sleep(.6)
    offer_style=script("const e=document.querySelector('#representation-date'),s=getComputedStyle(e),r=e.getBoundingClientRect();return {height:r.height,font:s.fontFamily+'|'+s.fontSize+'|'+s.fontWeight,padding:s.padding,border:s.borderWidth+'|'+s.borderStyle+'|'+s.borderColor,shared:!!document.querySelector('#representation-picker .cross-date-picker-calendar')};")
    assert offer_style['shared'] and offer_style['height']==find_style['height'] and offer_style['font']==find_style['font'] and offer_style['padding']==find_style['padding'] and offer_style['border']==find_style['border'],(find_style,offer_style)
    invalid_offer=script("const i=document.querySelector('#representation-date');i.value='31.02.2026';i.dispatchEvent(new Event('input',{bubbles:true}));return {invalid:i.getAttribute('aria-invalid'),error:document.querySelector('#representation-date-error').textContent,chips:document.querySelectorAll('#representation-date-chips .date-chip').length};")
    assert invalid_offer['invalid']=='true' and invalid_offer['error'] and invalid_offer['chips']==0,invalid_offer
    past_offer=script("const i=document.querySelector('#representation-date');i.value='01.01.2020';i.dispatchEvent(new Event('input',{bubbles:true}));return {invalid:i.getAttribute('aria-invalid'),error:document.querySelector('#representation-date-error').textContent};")
    assert past_offer['invalid']=='true' and 'heutiges oder zukünftiges' in past_offer['error'],past_offer
    valid_offer=script("const i=document.querySelector('#representation-date');i.value='31.12.2099';i.dispatchEvent(new Event('input',{bubbles:true}));return {invalid:i.getAttribute('aria-invalid'),chips:document.querySelectorAll('#representation-date-chips .date-chip').length,text:document.querySelector('#representation-date-chips').textContent};")
    assert valid_offer['invalid']=='false' and valid_offer['chips']==1 and '31.12.2099' in valid_offer['text'],valid_offer
    script("document.querySelector('#representation-picker [data-date-picker-trigger]').click()");time.sleep(.2)
    offer_calendar=script("const b=[...document.querySelectorAll('#representation-picker button[data-date]')],days=new Set(b.filter(x=>!x.disabled).map(x=>new Date(x.dataset.date+'T12:00:00Z').getUTCDay()));return {enabledWeekdays:days.size,pastDisabled:b.some(x=>x.dataset.date<'2099-12-31'&&x.disabled)};")
    assert offer_calendar['enabledWeekdays']>1,offer_calendar
    script("document.querySelector('#logout-button').click()");time.sleep(.4);request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.2)
    script("const f=document.querySelector('#login-form');f.login.value='representation-e@example.invalid';f.password.value='representation-test-123';f.requestSubmit();");time.sleep(.6);request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=vertretung-finden'});time.sleep(.6)
    missing=script("return {disabled:document.querySelector('#representation-request-picker [data-date-picker-trigger]').disabled,inputDisabled:document.querySelector('#representation-request-date').disabled,hint:document.querySelector('#representation-request-day-hint').textContent};")
    assert missing['disabled'] and missing['inputDisabled'] and 'kein regelmäßiger Meetingtag' in missing['hint'],missing
    script("document.querySelector('#logout-button').click()");time.sleep(.4);request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.2)
    script("const f=document.querySelector('#login-form');f.login.value='admin';f.password.value='admin';f.requestSubmit();");time.sleep(.7)
    admin=script("const e=document.querySelector('.page-intro-title'),s=getComputedStyle(e),r=e.getBoundingClientRect();return {text:e.textContent.trim(),class:e.className,top:r.top,font:s.fontFamily+'|'+s.fontSize+'|'+s.fontWeight+'|'+s.color+'|'+s.lineHeight,margin:s.marginTop+'|'+s.marginBottom};")
    assert admin['text']=='Administration' and 'page-intro-title' in admin['class'],admin
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=crosschaptern'});time.sleep(.4)
    public=script("const e=document.querySelector('.page-intro-title'),s=getComputedStyle(e),r=e.getBoundingClientRect();return {top:r.top,font:s.fontFamily+'|'+s.fontSize+'|'+s.fontWeight+'|'+s.color+'|'+s.lineHeight,margin:s.marginTop+'|'+s.marginBottom};")
    assert admin['font']==public['font'] and admin['margin']==public['margin'] and abs(admin['top']-public['top'])<1,(admin,public)
    logs=request('POST',f'/session/{session}/log',{'type':'browser'});assert not [x for x in logs if x.get('level')=='SEVERE' and '/api/representation/requests.php' not in x.get('message','')],logs
    performance=request('POST',f'/session/{session}/log',{'type':'performance'});assert not [x for x in performance if 'bni.de' in x.get('message','') or 'bni-' in x.get('message','')],performance
    print('PASS Chromium: Admin-Titel, Meetingtag-Kalender, Direktanlage, HTTP-400-Manipulationsschutz, Escape und Mobil')
finally:request('DELETE',f'/session/{session}')
