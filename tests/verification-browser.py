#!/usr/bin/env python3
import json, os, time, urllib.parse, urllib.request

BASE = 'http://127.0.0.1:9517'
TOKEN = os.environ['CROSSCHAPP_VERIFY_TOKEN']
EMAIL = os.environ['CROSSCHAPP_VERIFY_EMAIL']

def request(method, path, payload=None):
    req = urllib.request.Request(BASE + path, data=None if payload is None else json.dumps(payload).encode(), method=method, headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(req) as response:
        return json.loads(response.read())['value']

session = request('POST', '/session', {'capabilities': {'alwaysMatch': {'browserName': 'chrome', 'goog:chromeOptions': {'args': ['--headless', '--no-sandbox', '--disable-gpu', '--window-size=1280,900']}, 'goog:loggingPrefs': {'browser': 'ALL', 'performance': 'ALL'}}}})['sessionId']
def script(source): return request('POST', f'/session/{session}/execute/sync', {'script': source, 'args': []})

try:
    verify_url = 'http://localhost:8082/?verify=' + urllib.parse.quote(TOKEN)
    request('POST', f'/session/{session}/url', {'url': verify_url}); time.sleep(.5)
    success = script("return {url:location.href,text:document.querySelector('.verification-panel p')?.textContent,hasLogin:!!document.querySelector('.verification-panel a'),crosschaptern:document.body.textContent.includes('Chapter in Deutschland und Österreich')};")
    assert success['text'] == 'E-Mail-Adresse erfolgreich bestätigt.' and success['hasLogin'] and not success['crosschaptern'], success
    assert 'view=verified' in success['url'] and 'verify=' not in success['url'] and TOKEN not in success['url'], success
    button_style = script("const s=getComputedStyle(document.querySelector('.verification-panel a'));return {color:s.color,background:s.backgroundColor};")
    assert button_style['color'] == 'rgb(255, 255, 255)', button_style
    button = request('POST', f'/session/{session}/element', {'using': 'css selector', 'value': '.verification-panel a'})
    request('POST', f'/session/{session}/actions', {'actions': [{'type': 'pointer', 'id': 'mouse', 'parameters': {'pointerType': 'mouse'}, 'actions': [{'type': 'pointerMove', 'duration': 100, 'origin': button, 'x': 0, 'y': 0}]}]})
    hover_background = script("return getComputedStyle(document.querySelector('.verification-panel a')).backgroundColor;")
    assert hover_background != button_style['background'], (button_style, hover_background)
    script("document.querySelector('.verification-panel a').click();"); time.sleep(.3)
    assert 'view=login' in script('return location.href;')
    script(f"const f=document.querySelector('#login-form');f.login.value='{EMAIL}';f.password.value='12345678';f.requestSubmit();"); time.sleep(.7)
    assert script("return document.querySelector('#account-menu-trigger')?.textContent.includes('UI Test');")
    trigger = request('POST', f'/session/{session}/element', {'using': 'css selector', 'value': '#account-menu-trigger'})
    request('POST', f'/session/{session}/actions', {'actions': [{'type': 'pointer', 'id': 'account-mouse', 'parameters': {'pointerType': 'mouse'}, 'actions': [{'type': 'pointerMove', 'duration': 100, 'origin': trigger, 'x': 0, 'y': 0}]}]})
    assert script("return !document.querySelector('#account-dropdown').hidden && document.querySelector('#account-menu-trigger').getAttribute('aria-expanded')==='true';")
    typography=script("const trigger=getComputedStyle(document.querySelector('#account-menu-trigger')),item=getComputedStyle(document.querySelector('#open-change-password'));return {trigger:[trigger.fontFamily,trigger.fontSize,trigger.fontWeight,trigger.lineHeight],item:[item.fontFamily,item.fontSize,item.fontWeight,item.lineHeight]};")
    assert typography['trigger']==typography['item'],typography
    request('POST',f'/session/{session}/window/rect',{'width':390,'height':844});time.sleep(.2)
    mobile=script("const r=document.querySelector('#account-dropdown').getBoundingClientRect(),t=getComputedStyle(document.querySelector('#account-menu-trigger')),i=getComputedStyle(document.querySelector('#open-change-password'));return {inside:r.left>=0&&r.right<=innerWidth,same:t.fontSize===i.fontSize&&t.fontFamily===i.fontFamily};");assert mobile['inside'] and mobile['same'],mobile
    request('POST',f'/session/{session}/window/rect',{'width':1280,'height':900})
    script("document.querySelector('#account-menu-trigger').click();document.querySelector('#account-menu-trigger').click();document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape'}));")
    assert script("return document.querySelector('#account-dropdown').hidden && document.querySelector('#account-menu-trigger').getAttribute('aria-expanded')==='false';")
    script("document.querySelector('#account-menu-trigger').click();document.body.click();")
    assert script("return document.querySelector('#account-dropdown').hidden;")
    script("document.querySelector('#account-menu-trigger').click();document.querySelector('#open-change-password').click();")
    assert script("return document.querySelector('#change-password-dialog').open;")
    request('POST', f'/session/{session}/log', {'type': 'performance'})
    script("const f=document.querySelector('#change-password-form');f.password.value='nicht-speichern';f.password_confirmation.value='nicht-speichern';document.querySelector('#cancel-change-password').click();")
    assert not script("return document.querySelector('#change-password-dialog').open;")
    cancel_network=request('POST',f'/session/{session}/log',{'type':'performance'});assert not [entry for entry in cancel_network if '/api/auth/change-password.php' in entry.get('message','')],cancel_network
    script("document.querySelector('#account-menu-trigger').click();document.querySelector('#open-change-password').click();")
    fresh=script("const f=document.querySelector('#change-password-form');return {password:f.password.value,confirmation:f.password_confirmation.value};");assert fresh=={'password':'','confirmation':''},fresh
    validation = script("const f=document.querySelector('#change-password-form');f.password.value='1234567';f.password.dispatchEvent(new Event('blur'));const short=!document.querySelector('#change-password-error').hidden;f.password.value='12345678';f.password_confirmation.value='abcdefgh';f.password_confirmation.dispatchEvent(new Event('blur'));const mismatch=!document.querySelector('#change-confirmation-error').hidden;f.password_confirmation.value='12345678';f.password_confirmation.dispatchEvent(new Event('blur'));f.password.value='87654321';f.password.dispatchEvent(new Event('input',{bubbles:true}));return {short,mismatch,rechecked:!document.querySelector('#change-confirmation-error').hidden};")
    assert all(validation.values()), validation
    script("const f=document.querySelector('#change-password-form');f.password.value='87654321';f.password_confirmation.value='87654321';f.requestSubmit();");time.sleep(.5)
    changed=script("const c=document.querySelector('#change-password-content');return {text:c.textContent.trim(),form:!!c.querySelector('form'),session:!!document.querySelector('#account-menu-trigger')};")
    assert changed=={'text':'Passwort wurde erfolgreich geändert.','form':False,'session':True},changed
    script("document.querySelector('#close-change-password').click();document.querySelector('#logout-button').click();"); time.sleep(.4)
    request('POST', f'/session/{session}/url', {'url': 'http://localhost:8082/?view=login'}); time.sleep(.2)
    script(f"const f=document.querySelector('#login-form');f.login.value='{EMAIL}';f.password.value='12345678';f.requestSubmit();");time.sleep(.4)
    assert 'nicht korrekt' in script("return document.querySelector('#login-message').textContent;")
    expected_login_logs=request('POST',f'/session/{session}/log',{'type':'browser'});assert not [entry for entry in expected_login_logs if entry.get('level')=='SEVERE' and '/api/auth/login.php' not in entry.get('message','')],expected_login_logs
    script(f"const f=document.querySelector('#login-form');f.password.value='87654321';f.requestSubmit();");time.sleep(.6)
    assert script("return document.querySelector('#account-menu-trigger')?.textContent.includes('UI Test');")
    script("document.querySelector('#logout-button').click();"); time.sleep(.3)

    request('POST', f'/session/{session}/url', {'url': verify_url}); time.sleep(.4)
    assert script("return document.querySelector('.verification-panel p')?.textContent;") == 'Dieser Bestätigungslink wurde bereits verwendet.'
    request('POST', f'/session/{session}/url', {'url': 'http://localhost:8082/?verify=ungueltiger-token'}); time.sleep(.4)
    assert script("return document.querySelector('.verification-panel p')?.textContent;") == 'Dieser Bestätigungslink ist ungültig.'
    logs = request('POST', f'/session/{session}/log', {'type': 'browser'})
    assert not [entry for entry in logs if entry.get('level') == 'SEVERE'], logs
    print('PASS Chromium: Verifikationsrouting, Redirect ohne Token, Aktivierung, Login, verwendet, ungültig')
finally:
    request('DELETE', f'/session/{session}')
