#!/usr/bin/env python3
import json, time, urllib.request
BASE='http://127.0.0.1:9516'
def request(method,path,payload=None):
    req=urllib.request.Request(BASE+path,data=None if payload is None else json.dumps(payload).encode(),method=method,headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(req) as response:return json.loads(response.read())['value']
session=request('POST','/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome','goog:chromeOptions':{'args':['--headless','--no-sandbox','--disable-gpu','--window-size=1440,1000']},'goog:loggingPrefs':{'browser':'ALL'}}}})['sessionId']
def script(source):return request('POST',f'/session/{session}/execute/sync',{'script':source,'args':[]})
try:
    request('POST',f'/session/{session}/url',{'url':'http://localhost:8082/?view=login'});time.sleep(.3)
    script("const f=document.querySelector('#login-form');f.login.value='admin';f.password.value='admin';f.requestSubmit();");time.sleep(.7)
    assert 'view=admin' in script('return location.href;')
    script("document.querySelector('#automation-panel').open=true;document.querySelector('#automation-panel').dispatchEvent(new Event('toggle'));");time.sleep(.6)
    state=script("return {mapEnabled:document.querySelector('#map-refresh-enabled').checked,mapDays:document.querySelector('#map-refresh-days').value,stats:document.querySelector('#automation-stats').textContent,blocks:[...document.querySelectorAll('#automation-form .automation-setting h3')].map(x=>x.textContent)};")
    assert state['mapDays']=='1' and 'Automatische Grunddatenaktualisierung' in state['blocks'] and 'Grunddatenautomatik' in state['stats'],state
    invalid=script("const token=document.querySelector('meta[name=\"csrf-token\"]').content;return fetch('/api/automation/settings.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({usage_refresh_enabled:document.querySelector('#usage-refresh-enabled').checked,usage_refresh_days:Number(document.querySelector('#usage-refresh-days').value),automatic_refresh_enabled:document.querySelector('#automatic-refresh-enabled').checked,automatic_refresh_days:Number(document.querySelector('#automatic-refresh-days').value),automatic_refresh_daily_limit:Number(document.querySelector('#automatic-refresh-daily-limit').value),map_refresh_enabled:false,map_refresh_days:0})}).then(async r=>({status:r.status,payload:await r.json()}));")
    assert invalid['status']==400
    logs=request('POST',f'/session/{session}/log',{'type':'browser'});assert not [x for x in logs if x.get('level')=='SEVERE' and '/api/automation/settings.php' not in x.get('message','')],logs
    print('PASS Chromium: Z-Adminblock, persistente Werte, Statistik und Validierung')
finally:request('DELETE',f'/session/{session}')
