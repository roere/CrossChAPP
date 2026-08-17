#!/usr/bin/env python3
import json, time, urllib.request
BASE='http://127.0.0.1:9517'
def request(method,path,payload=None):
    req=urllib.request.Request(BASE+path,data=None if payload is None else json.dumps(payload).encode(),method=method,headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(req) as response:return json.loads(response.read())['value']
session=request('POST','/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome','goog:chromeOptions':{'args':['--headless','--no-sandbox','--disable-gpu','--window-size=1440,1000']},'goog:loggingPrefs':{'browser':'ALL','performance':'ALL'}}}})['sessionId']
test_email=f'crosschapp-ui-{int(time.time())}@example.test'
def script(source):return request('POST',f'/session/{session}/execute/sync',{'script':source,'args':[]})
try:
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=register'});time.sleep(1)
    registration=script("return {title:document.querySelector('h1').textContent,count:document.querySelectorAll('.home-chapter-result').length,adminHint:document.body.textContent.includes('CrossChAPP Admin')};")
    assert 'Neues Konto' in registration['title'] and registration['count']>0 and not registration['adminHint']
    validation=script("""
const f=document.querySelector('#register-form'), email=f.email, password=f.password, confirmation=f.password_confirmation;
email.value='ungueltig'; email.dispatchEvent(new Event('blur')); const badEmail=!document.querySelector('#register-email-error').hidden && email.getAttribute('aria-invalid')==='true';
email.value='test@example.test'; email.dispatchEvent(new Event('blur')); const goodEmail=document.querySelector('#register-email-error').hidden;
password.value='1234567'; password.dispatchEvent(new Event('blur')); const shortPassword=!document.querySelector('#register-password-error').hidden;
password.value='12345678'; password.dispatchEvent(new Event('blur')); const goodPassword=document.querySelector('#register-password-error').hidden;
confirmation.value='abcdefgh'; confirmation.dispatchEvent(new Event('blur')); const mismatch=!document.querySelector('#register-confirmation-error').hidden;
confirmation.value='12345678'; confirmation.dispatchEvent(new Event('blur')); const match=document.querySelector('#register-confirmation-error').hidden;
password.value='87654321'; password.dispatchEvent(new Event('input',{bubbles:true})); const changedAgain=!document.querySelector('#register-confirmation-error').hidden;
return {badEmail,goodEmail,shortPassword,goodPassword,mismatch,match,changedAgain,loginLink:[...document.querySelectorAll('.registration-panel a')].some(a=>a.textContent.trim()==='Zur Anmeldung')};
""")
    assert all(validation[key] for key in ['badEmail','goodEmail','shortPassword','goodPassword','mismatch','match','changedAgain']) and not validation['loginLink'],validation
    blocked=script("""const f=document.querySelector('#register-form');f.first_name.value='UI';f.last_name.value='Test';f.email.value='ungueltig';f.password.value='12345678';f.password_confirmation.value='12345678';f.requestSubmit();return {focus:document.activeElement===f.email,error:!document.querySelector('#register-email-error').hidden};""")
    assert blocked['focus'] and blocked['error'],blocked
    selected=script("const s=document.querySelector('#home-chapter-search');s.value='Königsforst';s.dispatchEvent(new Event('input',{bubbles:true}));const r=document.querySelector('.home-chapter-result input');r.click();return {id:document.querySelector('#home-chapter-id').value,text:document.querySelector('#selected-home-chapter').textContent,visible:document.querySelectorAll('.home-chapter-result').length};")
    assert selected['id'] and 'ausgewählt' in selected['text'] and selected['visible']>=1
    cleared=script("document.querySelector('#clear-home-chapter').click();return document.querySelector('#home-chapter-id').value;");assert cleared==''
    script(f"const f=document.querySelector('#register-form');f.first_name.value='UI';f.last_name.value='Test';f.email.value='{test_email}';f.password.value='12345678';f.password_confirmation.value='12345678';f.requestSubmit();");time.sleep(1)
    assert 'Konto wurde angelegt' in script("return document.querySelector('#register-message').textContent;")

    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.3)
    script("const f=document.querySelector('#login-form');f.login.value='admin';f.password.value='admin';f.requestSubmit();");time.sleep(1)
    admin=script("return {url:location.href,admin:[...document.querySelectorAll('nav a')].some(a=>a.textContent.trim()==='Admin'),misc:!!document.querySelector('#misc-panel')};")
    assert 'view=admin' in admin['url'] and admin['admin'] and admin['misc']
    script("document.querySelector('#misc-panel').open=true;document.querySelector('#misc-panel').dispatchEvent(new Event('toggle'));");time.sleep(.5)
    mail=script("return {password:document.querySelector('#mail-settings-form').elements.smtpPassword.value,templates:document.querySelectorAll('#email-templates-form textarea').length};")
    assert mail['password']=='' and mail['templates']==2
    script("document.querySelector('#logout-button').click();");time.sleep(.5)
    assert not script("return [...document.querySelectorAll('nav a')].some(a=>a.textContent.trim()==='Admin');")

    browser_logs=request('POST',f'/session/{session}/log',{'type':'browser'});assert not [x for x in browser_logs if x.get('level')=='SEVERE'],browser_logs
    performance=request('POST',f'/session/{session}/log',{'type':'performance'});urls=[]
    for entry in performance:
        try:
            message=json.loads(entry['message'])['message']
            if message['method']=='Network.requestWillBeSent':urls.append(message['params']['request']['url'])
        except (KeyError,ValueError):pass
    assert not [url for url in urls if 'bni.de' in url],urls
    print(f'PASS Chromium: Inline-Validierung, Registrierung ({test_email}), Heimatchapter, Adminlogin, Mailbereich, Logout, keine BNI-Requests')
finally:request('DELETE',f'/session/{session}')
