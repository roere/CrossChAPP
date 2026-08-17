#!/usr/bin/env python3
import json, time, urllib.request
BASE='http://127.0.0.1:9517'
def request(method,path,payload=None):
    req=urllib.request.Request(BASE+path,data=None if payload is None else json.dumps(payload).encode(),method=method,headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(req) as response:return json.loads(response.read())['value']
session=request('POST','/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome','goog:chromeOptions':{'args':['--headless','--no-sandbox','--disable-gpu','--window-size=1440,1000']},'goog:loggingPrefs':{'browser':'ALL','performance':'ALL'}}}})['sessionId']
test_email=f'crosschapp-ui-{int(time.time())}@example.test'
def script(source):return request('POST',f'/session/{session}/execute/sync',{'script':source,'args':[]})
def password_geometry():return script("const f=document.querySelector('#register-form'),p=f.password.getBoundingClientRect(),c=f.password_confirmation.getBoundingClientRect(),labels=[f.password.closest('label').getBoundingClientRect(),f.password_confirmation.closest('label').getBoundingClientRect()];return {pw:[p.x,p.y,p.width,p.height],confirmation:[c.x,c.y,c.width,c.height],labelTops:labels.map(x=>x.y)};")
def aligned(geometry):return abs(geometry['pw'][1]-geometry['confirmation'][1])<.1 and abs(geometry['pw'][2]-geometry['confirmation'][2])<.1 and abs(geometry['pw'][3]-geometry['confirmation'][3])<.1 and abs(geometry['labelTops'][0]-geometry['labelTops'][1])<.1
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
    for password,confirmation in [('12345678','12345678'),('1234567','1234567'),('12345678','abcdefgh'),('1234567','abcdefgh')]:
        script(f"const f=document.querySelector('#register-form');f.password.value='{password}';f.password_confirmation.value='{confirmation}';f.password.dispatchEvent(new Event('blur'));f.password_confirmation.dispatchEvent(new Event('blur'));")
        assert aligned(password_geometry()),password_geometry()
    request('POST',f'/session/{session}/window/rect',{'width':390,'height':844});time.sleep(.2)
    mobile=password_geometry();assert abs(mobile['pw'][2]-mobile['confirmation'][2])<.1 and abs(mobile['pw'][3]-mobile['confirmation'][3])<.1,mobile
    request('POST',f'/session/{session}/window/rect',{'width':1440,'height':1000})
    cancel=script("const f=document.querySelector('#register-form');f.first_name.value='Nicht';f.last_name.value='Speichern';return !!document.querySelector('#cancel-registration');");assert cancel
    request('POST',f'/session/{session}/log',{'type':'performance'})
    script("document.querySelector('#cancel-registration').click();");time.sleep(.5)
    assert script("return location.pathname==='/' && location.search==='' && !document.querySelector('#register-form');")
    cancel_network=request('POST',f'/session/{session}/log',{'type':'performance'});assert not [entry for entry in cancel_network if '/api/auth/register.php' in entry.get('message','')],cancel_network
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=register'});time.sleep(.5)
    fresh=script("const f=document.querySelector('#register-form');return {first:f.first_name.value,last:f.last_name.value,email:f.email.value};");assert fresh=={'first':'','last':'','email':''},fresh
    blocked=script("""const f=document.querySelector('#register-form');f.first_name.value='UI';f.last_name.value='Test';f.email.value='ungueltig';f.password.value='12345678';f.password_confirmation.value='12345678';f.requestSubmit();return {focus:document.activeElement===f.email,error:!document.querySelector('#register-email-error').hidden};""")
    assert blocked['focus'] and blocked['error'],blocked
    selected=script("const s=document.querySelector('#home-chapter-search');s.value='Königsforst';s.dispatchEvent(new Event('input',{bubbles:true}));const r=document.querySelector('.home-chapter-result input');r.click();return {id:document.querySelector('#home-chapter-id').value,text:document.querySelector('#selected-home-chapter').textContent,visible:document.querySelectorAll('.home-chapter-result').length};")
    assert selected['id'] and 'ausgewählt' in selected['text'] and selected['visible']>=1
    cleared=script("document.querySelector('#clear-home-chapter').click();return document.querySelector('#home-chapter-id').value;");assert cleared==''
    script(f"const f=document.querySelector('#register-form');f.first_name.value='UI';f.last_name.value='Test';f.email.value='{test_email}';f.password.value='12345678';f.password_confirmation.value='12345678';document.querySelector('#home-chapter-id').value='999999999';f.requestSubmit();");time.sleep(.5)
    failed=script("return {form:!!document.querySelector('#register-form'),error:document.querySelector('#register-message').textContent.length>0};");assert failed['form'] and failed['error'],failed
    expected_error_logs=request('POST',f'/session/{session}/log',{'type':'browser'})
    assert not [entry for entry in expected_error_logs if entry.get('level')=='SEVERE' and '/api/auth/register.php' not in entry.get('message','')],expected_error_logs
    script("document.querySelector('#home-chapter-id').value='';")
    script(f"const f=document.querySelector('#register-form');f.first_name.value='UI';f.last_name.value='Test';f.email.value='{test_email}';f.password.value='12345678';f.password_confirmation.value='12345678';f.requestSubmit();");time.sleep(1)
    success=script("const p=document.querySelector('.registration-panel');return {text:p.textContent.trim(),form:!!p.querySelector('form'),buttons:p.querySelectorAll('button').length};")
    assert success=={'text':'Bitte bestätige deine E-Mail-Adresse.','form':False,'buttons':0},success

    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.3)
    script("const f=document.querySelector('#login-form');f.login.value='admin';f.password.value='admin';f.requestSubmit();");time.sleep(1)
    admin=script("return {url:location.href,admin:[...document.querySelectorAll('nav a')].some(a=>a.textContent.trim()==='Admin'),misc:!!document.querySelector('#misc-panel')};")
    assert 'view=admin' in admin['url'] and admin['admin'] and admin['misc']
    time.sleep(.5);request('POST',f'/session/{session}/log',{'type':'performance'})
    admin_sort=script("""
const table=document.querySelector('#admin-organization-table'), click=key=>table.querySelector(`th[data-sort-key="${key}"] .sort-button`).click();
const result={aria:{},numeric:false,preserved:false,filtered:false};
for(const key of ['chapterName','orgId','country','type','city','meetingDay','meetingTime','detailStatus','detailsLoadedAt']){click(key);result.aria[key]=table.querySelector(`th[data-sort-key="${key}"]`).getAttribute('aria-sort');click(key);result.aria[key]+='/'+table.querySelector(`th[data-sort-key="${key}"]`).getAttribute('aria-sort');}
click('orgId');const ascending=[...document.querySelectorAll('tr.organization')].map(row=>Number(row.dataset.id));click('orgId');const descending=[...document.querySelectorAll('tr.organization')].map(row=>Number(row.dataset.id));result.numeric=ascending.every((v,i,a)=>!i||a[i-1]<=v)&&descending.every((v,i,a)=>!i||a[i-1]>=v);
const first=document.querySelector('tr.organization'),id=first.dataset.id;first.querySelector('input[type="checkbox"]').click();first.querySelector('.row-toggle').click();click('chapterName');result.preserved=!!document.querySelector(`tr.organization[data-id="${id}"] input:checked`)&&!!document.querySelector(`#details-${id}`);
const country=document.querySelector('#country-filter');country.value='AT';country.dispatchEvent(new Event('input',{bubbles:true}));click('city');result.filtered=[...document.querySelectorAll('tr.organization .column-country')].every(cell=>cell.textContent==='Österreich');
return result;
""")
    assert all(value=='ascending/descending' for value in admin_sort['aria'].values()) and admin_sort['numeric'] and admin_sort['preserved'] and admin_sort['filtered'],admin_sort
    sort_network=request('POST',f'/session/{session}/log',{'type':'performance'});assert not sort_network,sort_network
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
    print(f'PASS Chromium: Abbrechen, frischer Zustand, Fehler-/Erfolgsansicht, Validierung, Registrierung ({test_email}), Login, keine BNI-Requests')
finally:request('DELETE',f'/session/{session}')
