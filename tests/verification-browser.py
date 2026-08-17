#!/usr/bin/env python3
import json, os, time, urllib.parse, urllib.request

BASE = 'http://127.0.0.1:9517'
TOKEN = os.environ['CROSSCHAPP_VERIFY_TOKEN']
EMAIL = os.environ['CROSSCHAPP_VERIFY_EMAIL']

def request(method, path, payload=None):
    req = urllib.request.Request(BASE + path, data=None if payload is None else json.dumps(payload).encode(), method=method, headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(req) as response:
        return json.loads(response.read())['value']

session = request('POST', '/session', {'capabilities': {'alwaysMatch': {'browserName': 'chrome', 'goog:chromeOptions': {'args': ['--headless', '--no-sandbox', '--disable-gpu', '--window-size=1280,900']}, 'goog:loggingPrefs': {'browser': 'ALL'}}}})['sessionId']
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
    assert script("return document.querySelector('.account-user')?.textContent.trim()==='UI Test';")
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
