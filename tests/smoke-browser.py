#!/usr/bin/env python3
import datetime, json, os, re, time, urllib.request

DRIVER=os.environ.get('CROSSCHAPP_WEBDRIVER_URL','http://127.0.0.1:9519')
APP=os.environ.get('CROSSCHAPP_TEST_BASE_URL','http://127.0.0.1:18082')
FORBIDDEN=('bni.de','bni-rheinruhr.de','bniconnectglobal.com')
def wd(method,path,payload=None):
    request=urllib.request.Request(DRIVER+path,data=None if payload is None else json.dumps(payload).encode(),method=method,headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(request,timeout=20) as response:return json.loads(response.read())['value']
session=wd('POST','/session',{'capabilities':{'alwaysMatch':{'browserName':'chrome','goog:chromeOptions':{'args':['--headless','--no-sandbox','--disable-gpu','--window-size=1440,1000']},'goog:loggingPrefs':{'browser':'ALL','performance':'ALL'}}}})['sessionId']
def js(source):return wd('POST',f'/session/{session}/execute/sync',{'script':source,'args':[]})
def go(path):wd('POST',f'/session/{session}/url',{'url':APP+path});time.sleep(.6)
def login(email,password='check-password-123'):
    go('/?view=login');js(f"const f=document.querySelector('#login-form');f.login.value={json.dumps(email)};f.password.value={json.dumps(password)};f.requestSubmit();");time.sleep(.8)
def search(location='Testort', requests_only=False):
    js(f"const f=document.querySelector('#chapter-search-form');f.location.value={json.dumps(location)};f.elements.has_representation_requests.checked={str(requests_only).lower()};f.querySelector('[data-limit=\"all\"]').click();f.requestSubmit();")
    for _ in range(40):
        time.sleep(.25)
        if js("return !document.querySelector('#search-button').disabled"):return
    raise AssertionError('CrossChAPPtern-Suche blieb im Ladezustand')
try:
    wd('POST',f'/session/{session}/log',{'type':'performance'});go('/?view=about')
    guide=js("const nav=[...document.querySelectorAll('nav a')],steps=[...document.querySelectorAll('.guide-step')],images=[...document.querySelectorAll('.guide-image-button img')],rects=steps.map(step=>({copy:step.querySelector('.guide-step-copy').getBoundingClientRect(),image:step.querySelector('.guide-image-button').getBoundingClientRect()}));return {nav:nav.map(x=>x.textContent.trim()),active:nav.filter(x=>x.classList.contains('active')).map(x=>x.textContent.trim()),title:document.querySelector('.page-intro-title').textContent.trim(),headings:steps.map(x=>x.querySelector('h2').textContent.trim()),text:document.querySelector('.guide-page').innerText,loaded:images.map(x=>({complete:x.complete,naturalWidth:x.naturalWidth,naturalHeight:x.naturalHeight,displayWidth:x.getBoundingClientRect().width,displayHeight:x.getBoundingClientRect().height})),alternating:rects.map(x=>x.copy.left<x.image.left),cta:[...document.querySelectorAll('.guide-cta-actions a')].map(x=>x.textContent.trim()),overflow:document.documentElement.scrollWidth>document.documentElement.clientWidth}")
    assert guide['nav'][:2]==['Was ist CrossChAPP?','CrossChAPPtern'] and guide['active']==['Was ist CrossChAPP?'] and guide['title']=='Was ist CrossChAPP?',guide
    assert guide['headings']==['1. Passende Chapter finden','2. Vertretung anbieten','3. Vertretung für dein Chapter finden','4. Verifiziertes Benutzerkonto'],guide['headings']
    assert 'Gib an, für welche Termine Du eine Vertretung suchst.' in guide['text'] and 'Lege für dein Heimatchapter ein Vertretungsgesuch an.' not in guide['text'],guide['text']
    widths=[x['displayWidth'] for x in guide['loaded']]
    assert all(x['complete'] and x['naturalWidth']>0 and x['naturalHeight']>0 and abs((x['displayWidth']/x['displayHeight'])-(x['naturalWidth']/x['naturalHeight']))<.02 for x in guide['loaded']) and max(widths)-min(widths)<=2 and guide['alternating']==[True,False,True,False] and guide['cta']==['CrossChAPPtern öffnen','Anmelden'] and 'Geschäftsreise' in guide['text'] and 'Urlaub' in guide['text'] and not guide['overflow'],guide
    image_status=js("return Promise.all([...document.querySelectorAll('.guide-image-button img')].map(x=>fetch(x.src).then(r=>r.status)))");assert image_status==[200,200,200,200],image_status
    guide_network=wd('POST',f'/session/{session}/log',{'type':'performance'});guide_urls=[]
    for entry in guide_network:
        try:
            message=json.loads(entry['message'])['message']
            if message['method']=='Network.requestWillBeSent':guide_urls.append(message['params']['request']['url'])
        except (KeyError,ValueError):pass
    assert not [url for url in guide_urls if '/api/' in url or any(host in url for host in FORBIDDEN)],guide_urls
    dialog=js("const b=document.querySelector('.guide-image-button');b.click();return {open:document.querySelector('#guide-image-dialog').open,focus:document.activeElement.id,src:document.querySelector('#guide-image-dialog img').getAttribute('src')}");assert dialog['open'] and dialog['focus']=='close-guide-image' and dialog['src'].endswith('crosschaptern-finden.png'),dialog
    wd('POST',f'/session/{session}/actions',{'actions':[{'type':'key','id':'keyboard','actions':[{'type':'keyDown','value':'\ue00c'},{'type':'keyUp','value':'\ue00c'}]}]});time.sleep(.2)
    assert js("return !document.querySelector('#guide-image-dialog').open&&document.activeElement===document.querySelector('.guide-image-button')")
    wd('POST',f'/session/{session}/window/rect',{'width':390,'height':900});time.sleep(.3)
    mobile_guide=js("const steps=[...document.querySelectorAll('.guide-step')];return {single:steps.every(x=>getComputedStyle(x).gridTemplateColumns.split(' ').length===1),ordered:steps.every(x=>x.querySelector('.guide-step-copy').getBoundingClientRect().top<x.querySelector('.guide-image-button').getBoundingClientRect().top),overflow:document.documentElement.scrollWidth>document.documentElement.clientWidth,navVisible:[...document.querySelectorAll('nav a')].every(x=>x.getBoundingClientRect().width>0)}")
    assert mobile_guide=={'single':True,'ordered':True,'overflow':False,'navVisible':True},mobile_guide
    wd('POST',f'/session/{session}/window/rect',{'width':1440,'height':1000});time.sleep(.3)
    go('/?view=crosschaptern')
    assert js("return document.querySelector('.page-intro-title').textContent.trim()")=='Finde passende BNI-Chaptertreffen in deiner Nähe.'
    search_filter=js("const f=document.querySelector('#chapter-search-form'),c=f.elements.has_representation_requests,b=document.querySelector('#search-button');return {unchecked:!c.checked,before:!!(c.compareDocumentPosition(b)&Node.DOCUMENT_POSITION_FOLLOWING),label:c.closest('label').textContent.trim(),basis:document.body.innerText.includes('Datengrundlage:')}")
    assert search_filter=={'unchecked':True,'before':True,'label':'Nur Chapter mit Vertretungsgesuchen anzeigen','basis':False},search_filter
    search();anonymous=js("const c=document.querySelector('#result-910001');return {text:c?.textContent||'',buttons:c?.querySelectorAll('.request-contact-button').length||0,type:[...document.querySelectorAll('th,dt,label,legend')].some(x=>x.textContent.trim()==='Typ'),org:/orgId/i.test(document.body.innerText),error:document.querySelector('#search-message').textContent,ids:[...document.querySelectorAll('.result-card')].map(x=>x.id)}")
    assert 'Gesuch 1' in anonymous['text'] and 'Person 1' not in anonymous['text'] and anonymous['buttons']==1 and not anonymous['type'] and not anonymous['org'],anonymous
    assert 'result-910003' in anonymous['ids'],anonymous
    search(requests_only=True);request_filtered=js("return {checked:document.querySelector('[name=has_representation_requests]').checked,ids:[...document.querySelectorAll('.result-card')].map(x=>x.id)}")
    assert request_filtered=={'checked':True,'ids':['result-910001','result-910002']},request_filtered
    invalid_request_filter=js("return fetch('/api/search.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'Testort',days:[],time:'any',sort:'distance',limit:'all',hasRepresentationRequests:'yes'})}).then(async r=>({status:r.status,body:await r.json()}))")
    assert invalid_request_filter['status']==400 and 'ungültig' in invalid_request_filter['body']['error'],invalid_request_filter
    invalid_filter_logs=wd('POST',f'/session/{session}/log',{'type':'browser'})
    assert invalid_filter_logs and all('/api/search.php' in entry.get('message','') and '400' in entry.get('message','') for entry in invalid_filter_logs if entry.get('level')=='SEVERE'),invalid_filter_logs
    search()
    public_privacy=js("return fetch('/api/search.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'Testort',days:[],time:'any',sort:'distance',limit:'all'})}).then(r=>r.json()).then(data=>{const raw=JSON.stringify(data.results.find(x=>x.orgId===910001).representationRequests);return {userId:raw.includes('user_id'),email:raw.includes('@'),displayName:raw.includes('displayName')}})")
    assert not any(public_privacy.values()),public_privacy
    js("document.querySelector('#result-910001 .request-contact-button').click()");time.sleep(.4)
    assert js("const f=document.querySelector('#anonymous-request-contact-fields');return [...f.querySelectorAll('input')].filter(x=>x.getClientRects().length).length")==3
    js("document.querySelector('#cancel-request-contact').click()")
    go('/?view=vertretung');assert 'Um Vertretungsangebote zu machen musst du angemeldet sein.' in js('return document.body.innerText')
    assert not js("return !!document.querySelector('#representation-list')")
    js("document.querySelector('.representation-access-hint a').click()");time.sleep(.3)
    assert js("return document.querySelector('#login-form').return_view.value")=='vertretung'
    js("const f=document.querySelector('#login-form');f.login.value='check-a@example.test';f.password.value='falsch';f.requestSubmit()");time.sleep(.4)
    assert js("return document.querySelector('#login-form').return_view.value")=='vertretung'
    js("const f=document.querySelector('#login-form');f.password.value='check-password-123';f.requestSubmit()");time.sleep(.8)
    return_offer=js("return {view:new URL(location.href).searchParams.get('view'),badge:document.querySelector('#account-menu-trigger .verification-badge')?.getAttribute('aria-label'),title:document.querySelector('#account-menu-trigger .verification-badge')?.title}")
    assert return_offer=={'view':'vertretung','badge':'Verifiziert','title':'Verifiziert'},return_offer
    js("document.querySelector('#account-menu-trigger').click();document.querySelector('#open-my-account').click()");time.sleep(.3)
    assert js("return document.querySelector('[data-account-field=verificationStatus]').textContent.trim()")=='Verifiziert'
    js("document.querySelector('#close-my-account').click();document.querySelector('#logout-button').click()");time.sleep(.5)
    go('/?view=vertretung-finden');assert 'musst du angemeldet sein' in js('return document.body.innerText')
    js("document.querySelector('.representation-access-hint a').click()");time.sleep(.3)
    assert js("return document.querySelector('#login-form').return_view.value")=='vertretung-finden'
    js("const f=document.querySelector('#login-form');f.login.value='check-b@example.test';f.password.value='check-password-123';f.requestSubmit()");time.sleep(.8)
    assert js("return new URL(location.href).searchParams.get('view')")=='vertretung-finden'
    assert not js("return !!document.querySelector('#account-menu-trigger .verification-badge')")
    js("document.querySelector('#logout-button').click()");time.sleep(.4)
    go('/?view=login&return_view=https://evil.example');assert js("return document.querySelector('#login-form').return_view.value")==''
    js("const f=document.querySelector('#login-form');f.login.value='check-b@example.test';f.password.value='check-password-123';f.requestSubmit()");time.sleep(.8)
    assert js("return new URL(location.href).searchParams.get('view')")=='crosschaptern'
    js("document.querySelector('#logout-button').click()");time.sleep(.4)
    anonymous_admin=js("const t=document.querySelector('meta[name=csrf-token]').content;return fetch('/api/admin/invitations.php',{headers:{'X-CSRF-Token':t}}).then(r=>r.status)")
    assert anonymous_admin in (401,403),anonymous_admin
    anonymous_users=js("return fetch('/api/admin/users.php').then(r=>r.status)");assert anonymous_users==401,anonymous_users
    anonymous_user_actions=js("return Promise.all([fetch('/api/admin/user-password-reset.php',{method:'POST'}).then(r=>r.status),fetch('/api/admin/users.php',{method:'DELETE'}).then(r=>r.status)])");assert anonymous_user_actions==[401,401],anonymous_user_actions
    expected_auth_logs=wd('POST',f'/session/{session}/log',{'type':'browser'});assert not [entry for entry in expected_auth_logs if entry.get('level')=='SEVERE' and not any(path in entry.get('message','') for path in ('/api/auth/login.php','/api/admin/invitations.php','/api/admin/users.php','/api/admin/user-password-reset.php'))],expected_auth_logs

    login('check-b@example.test');assert js("return new URL(location.href).searchParams.get('view')")=='crosschaptern';go('/?view=about')
    assert js("return [...document.querySelectorAll('.guide-cta-actions a')].map(x=>x.textContent.trim())")==['CrossChAPPtern','Vertretung anbieten','Vertretung finden']
    go('/?view=crosschaptern');search()
    account_menu=js("const t=document.querySelector('#account-menu-trigger');t.click();const labels=[...document.querySelectorAll('#account-dropdown [role=menuitem]')].map(x=>x.textContent.trim());return {labels,open:!document.querySelector('#account-dropdown').hidden}")
    assert account_menu=={'labels':['Mein Konto','Passwort ändern'],'open':True},account_menu
    js("document.querySelector('#open-my-account').click()");time.sleep(.4)
    account_view=js("const d=document.querySelector('#my-account-dialog');return {open:d.open,values:Object.fromEntries([...d.querySelectorAll('[data-account-field]')].map(x=>[x.dataset.accountField,x.textContent])),deleteButton:!!d.querySelector('#open-delete-account'),private:/user_id|password_hash|token_hash/i.test(d.innerText)}")
    assert account_view['open'] and account_view['values']=={'firstName':'Bernd','lastName':'Beta','email':'check-b@example.test','homeChapterName':'Testchapter Rhein','verificationStatus':'BNI-Datensatz gefunden'} and account_view['deleteButton'] and not account_view['private'],account_view
    missing_csrf=js("return fetch('/api/auth/account.php',{method:'DELETE'}).then(r=>r.status)");assert missing_csrf==403,missing_csrf
    foreign_selector=js("const t=document.querySelector('meta[name=csrf-token]').content;return fetch('/api/auth/account.php',{method:'DELETE',headers:{'Content-Type':'application/json','X-CSRF-Token':t},body:JSON.stringify({user_id:1})}).then(r=>r.status)");assert foreign_selector==400,foreign_selector
    js("document.querySelector('#open-delete-account').click();document.querySelector('#cancel-delete-account').click()");
    assert js("return document.querySelector('#my-account-dialog').open&&!document.querySelector('#delete-account-dialog').open")
    user_admin=js("return fetch('/api/admin/invitations.php').then(r=>r.status)");assert user_admin in (401,403),user_admin
    user_users_api=js("return fetch('/api/admin/users.php').then(r=>r.status)");assert user_users_api==403,user_users_api
    user_actions_api=js("return Promise.all([fetch('/api/admin/user-password-reset.php',{method:'POST'}).then(r=>r.status),fetch('/api/admin/users.php',{method:'DELETE'}).then(r=>r.status)])");assert user_actions_api==[403,403],user_actions_api
    csrf_guard=js("return fetch('/api/representation/requests.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({request_date:'2099-01-01'})}).then(r=>r.status)");assert csrf_guard==403,csrf_guard
    expected_user_security_logs=wd('POST',f'/session/{session}/log',{'type':'browser'});assert not [entry for entry in expected_user_security_logs if entry.get('level')=='SEVERE' and not any(path in entry.get('message','') for path in ('/api/auth/account.php','/api/admin/invitations.php','/api/admin/users.php','/api/admin/user-password-reset.php','/api/representation/requests.php'))],expected_user_security_logs
    foreign=js("const c=document.querySelector('#result-910001');return {text:c.textContent,buttons:c.querySelectorAll('.request-contact-button').length}")
    assert 'Anna A.' in foreign['text'] and 'Dein Gesuch' not in foreign['text'] and foreign['buttons']==1,foreign
    js("document.querySelector('#result-910001 .request-contact-button').click()");time.sleep(.4)
    logged_fields=js("const f=document.querySelector('#anonymous-request-contact-fields');return {visible:[...f.querySelectorAll('input')].filter(x=>x.getClientRects().length).length,preview:document.querySelector('#request-contact-after').textContent,raw:document.querySelector('#request-contact-content').textContent.includes('{{')}")
    assert logged_fields['visible']==0 and 'Bernd Beta' in logged_fields['preview'] and 'check-b@example.test' in logged_fields['preview'] and not logged_fields['raw'],logged_fields
    js("document.querySelector('#request-contact-form').requestSubmit()");time.sleep(.7);assert 'Deine Rückmeldung wurde gesendet.' in js("return document.querySelector('#request-contact-content').textContent")

    go('/?view=vertretung');today=datetime.date.today();delta=(2-today.weekday())%7;wrong_date=today+datetime.timedelta(days=delta or 7);wrong_display=wrong_date.strftime('%d.%m.%Y')
    representation_copy=js("return {database:document.body.innerText.includes('Lokale Datenbank'),heading:document.querySelector('#representation-list-heading').textContent.trim()}")
    assert not representation_copy['database'] and representation_copy['heading']=='Chapterliste' and js("return document.querySelector('#select-visible-representations').textContent.trim()")=='Alle auswählen' and 'Alle sichtbaren auswählen' not in js("return document.querySelector('.representation-list-panel').innerText"),representation_copy
    representation_api=js("return fetch('/api/representation/organizations.php').then(r=>r.json()).then(p=>({count:p.count,types:[...new Set(p.organizations.map(x=>x.orgType))],ids:p.organizations.map(x=>x.orgId)}))")
    assert representation_api=={'count':3,'types':['CHAPTER'],'ids':[910001,910002,910003]},representation_api
    offer_actions=js("const panel=document.querySelector('.representation-list-panel'),heading=panel.querySelector('.list-heading'),actions=panel.querySelector('.representation-list-actions'),table=panel.querySelector('.table-scroll');return {order:!!((heading.compareDocumentPosition(actions)&Node.DOCUMENT_POSITION_FOLLOWING)&&(actions.compareDocumentPosition(table)&Node.DOCUMENT_POSITION_FOLLOWING)),filterButtons:document.querySelector('.representation-filters .representation-actions')===null,overflow:document.documentElement.scrollWidth>document.documentElement.clientWidth}")
    assert offer_actions=={'order':True,'filterButtons':True,'overflow':False},offer_actions
    selection_actions=js("const current=()=>[...document.querySelectorAll('#representation-list input[data-org-id]:not(:disabled)')],visible=current().length;document.querySelector('#select-visible-representations').click();const selected=current().filter(x=>x.checked).length;document.querySelector('#clear-representations').click();return {visible,selected,cleared:current().every(x=>!x.checked)}")
    assert selection_actions['visible']>0 and selection_actions['selected']==selection_actions['visible'] and selection_actions['cleared'],selection_actions
    wd('POST',f'/session/{session}/log',{'type':'performance'})
    invalid_client=js(f"const row=document.querySelector('input[data-org-id=\"910001\"]');row.click();const input=document.querySelector('#representation-date');input.value={json.dumps(wrong_display)};input.dispatchEvent(new Event('input',{{bubbles:true}}));return {{chips:document.querySelectorAll('#representation-date-chips .date-chip').length,message:document.querySelector('#representation-date-message').textContent,selected:row.checked}}")
    invalid_network=wd('POST',f'/session/{session}/log',{'type':'performance'});assert invalid_client['chips']==0 and invalid_client['selected'] and 'passt nicht zum Meetingtag' in invalid_client['message'] and not [x for x in invalid_network if '/api/representation/offers.php' in x.get('message','') and 'Network.requestWillBeSent' in x.get('message','')],invalid_client
    invalid_server=js(f"const t=document.querySelector('meta[name=csrf-token]').content;return fetch('/api/representation/offers.php').then(r=>r.json()).then(before=>fetch('/api/representation/offers.php',{{method:'POST',headers:{{'Content-Type':'application/json','X-CSRF-Token':t}},body:JSON.stringify({{orgIds:[910001],allDates:false,dates:[{json.dumps(wrong_date.isoformat())}]}})}}).then(async r=>({{status:r.status,payload:await r.json(),before:before.offers.length}}))).then(result=>fetch('/api/representation/offers.php').then(r=>r.json()).then(after=>({{...result,after:after.offers.length}})))")
    assert invalid_server['status']==400 and 'passt nicht zum Meetingtag' in invalid_server['payload']['error'] and invalid_server['before']==invalid_server['after'],invalid_server
    invalid_server_logs=wd('POST',f'/session/{session}/log',{'type':'browser'});assert not [entry for entry in invalid_server_logs if entry.get('level')=='SEVERE' and '/api/representation/offers.php' not in entry.get('message','')],invalid_server_logs
    offer=js("document.querySelector('#representation-all-dates').click();document.querySelector('#save-representation-offer').click();return {ownDisabled:document.querySelector('input[data-org-id=\"910002\"]').disabled,typeHeader:[...document.querySelectorAll('#representation-table th')].some(x=>x.textContent.trim()==='Typ')}");time.sleep(.7)
    assert offer['ownDisabled'] and not offer['typeHeader'] and 'Vertretungsangebot' in js("return document.querySelector('#representation-save-message').textContent")
    go('/?view=vertretung-finden');find_copy=js("return {title:document.querySelector('.page-intro-title').textContent.trim(),heading:document.querySelector('#representation-requests-heading').textContent.trim(),hint:document.querySelector('.representation-requests>p').textContent.trim(),old:document.querySelector('.representation-requests').innerText.includes('Termine auswählen'),home:document.querySelector('#representation-home-chapter').textContent}");assert find_copy['title']=='Finde eine Vertretung' and find_copy['heading']=='Meine Vertretungsgesuche' and find_copy['hint']=='Wähle Termine aus, für die Du eine Vertretung suchst' and not find_copy['old'] and 'Testchapter Rhein' in find_copy['home'],find_copy
    assert js("const s=document.querySelector('#all-dates-representations-section');return s.hidden&&!s.getClientRects().length&&!document.body.innerText.includes('Aktuell bietet sich niemand pauschal für alle Chaptertermine an.')")
    assert js("return document.querySelectorAll('#dated-representations .representation-provider-card').length")>=1
    today=datetime.date.today();delta=(2-today.weekday())%7;request_date=today+datetime.timedelta(days=delta or 7);display=request_date.strftime('%d.%m.%Y')
    js(f"const i=document.querySelector('#representation-request-date');i.value={json.dumps(display)};i.dispatchEvent(new Event('input',{{bubbles:true}}));");time.sleep(.7)
    assert js("return document.querySelectorAll('#representation-request-chips .date-chip').length")>=1
    go('/?view=crosschaptern');search();own=js("const c=document.querySelector('#result-910002');const row=[...c.querySelectorAll('.result-representation-request')].find(x=>x.textContent.includes('Bernd B.'));return {text:row?.textContent||'',buttons:row?.querySelectorAll('.request-contact-button').length||0}")
    assert 'Dein Gesuch' in own['text'] and own['buttons']==0,own
    js("document.querySelector('#logout-button').click()");time.sleep(.4);login('check-delete@example.test')
    js("document.querySelector('#account-menu-trigger').click();document.querySelector('#open-my-account').click()");time.sleep(.3)
    assert js("return {first:document.querySelector('[data-account-field=firstName]').textContent,verification:document.querySelector('[data-account-field=verificationStatus]').textContent,badge:!!document.querySelector('#account-menu-trigger .verification-badge')}")=={'first':'Dora','verification':'Nicht verifiziert','badge':False}
    js("document.querySelector('#open-delete-account').click();document.querySelector('#confirm-delete-account').click()");time.sleep(.9)
    deleted=js("return fetch('/api/auth/status.php').then(r=>r.json()).then(x=>({authenticated:x.authenticated,menu:!!document.querySelector('#account-menu'),url:location.href}))")
    assert not deleted['authenticated'] and not deleted['menu'] and 'account_deleted=1' in deleted['url'],deleted
    old_session=js("return fetch('/api/auth/account.php').then(r=>r.status)");assert old_session==401,old_session
    login('check-delete@example.test');assert 'nicht korrekt' in js("return document.querySelector('#login-message').textContent")
    deleted_account_security_logs=wd('POST',f'/session/{session}/log',{'type':'browser'});assert not [entry for entry in deleted_account_security_logs if entry.get('level')=='SEVERE' and not any(path in entry.get('message','') for path in ('/api/auth/account.php','/api/auth/login.php'))],deleted_account_security_logs
    login('admin','admin');
    assert 'view=admin' in js('return location.href')
    users_panel_initial=js("const u=document.querySelector('#users-panel'),i=document.querySelector('#invitations-panel');return {closed:!u.open,before:!!(u.compareDocumentPosition(i)&Node.DOCUMENT_POSITION_FOLLOWING)}");assert all(users_panel_initial.values()),users_panel_initial
    wd('POST',f'/session/{session}/log',{'type':'performance'});js("document.querySelector('#users-panel').open=true;document.querySelector('#users-panel').dispatchEvent(new Event('toggle'))");time.sleep(.6)
    users_overview=js("const rows=[...document.querySelectorAll('#users-list tr')],cells=r=>[...r.cells].map(x=>x.textContent.trim()),stats=[...document.querySelectorAll('#users-stats>div')].map(x=>x.textContent.trim());return {count:rows.length,rows:rows.map(cells),stats,emails:rows.map(r=>r.cells[2].textContent.trim()),radios:rows.map(r=>r.querySelectorAll('input[type=radio]').length),rowActions:rows.reduce((n,r)=>n+r.querySelectorAll('button').length,0),selected:document.querySelectorAll('#users-list input:checked').length,buttons:[document.querySelector('#reset-selected-user').disabled,document.querySelector('#delete-selected-user').disabled],message:document.querySelector('#users-message').textContent}")
    assert users_overview['count']==3 and 'admin@localhost.invalid' not in users_overview['emails'] and users_overview['radios']==[1,1,1] and users_overview['rowActions']==0 and users_overview['selected']==0 and users_overview['buttons']==[True,True] and any(r[1]=='Anna Alpha' and r[3]=='Testchapter Königsforst' and r[5]=='Verifiziert' and r[6]=='2' and r[7]=='1' for r in users_overview['rows']) and any(r[1]=='Bernd Beta' and r[5]=='BNI-Datensatz gefunden' and r[7]=='2' for r in users_overview['rows']) and any(r[1]=='Carla Gamma' and r[3]=='—' and r[4]=='Ausstehend' and r[5]=='Nicht verifiziert' and r[9]=='Nein' for r in users_overview['rows']),users_overview
    assert users_overview['stats']==['3 Anwender','2 aktive Anwender','1 verifiziert','2 mit Heimatchapter'],users_overview['stats']
    users_filter_sort=js("const rows=()=>[...document.querySelectorAll('#users-list tr')],pick=email=>rows().find(r=>r.cells[2].textContent.trim()===email).querySelector('input[type=radio]').click(),search=document.querySelector('#users-search'),status=document.querySelector('#users-status-filter'),verification=document.querySelector('#users-verification-filter');pick('check-a@example.test');const selectedA=rows().find(r=>r.cells[2].textContent.trim()==='check-a@example.test').classList.contains('is-selected');pick('check-b@example.test');const onlyB=document.querySelectorAll('#users-list input:checked').length===1&&rows().find(r=>r.cells[2].textContent.trim()==='check-b@example.test').classList.contains('is-selected');search.value='Carla';search.dispatchEvent(new Event('input',{bubbles:true}));const searched=rows().length,hiddenSelection=document.querySelectorAll('#users-list input:checked').length;search.value='';search.dispatchEvent(new Event('input',{bubbles:true}));const restored=rows().find(r=>r.cells[2].textContent.trim()==='check-b@example.test').querySelector('input').checked;status.value='pending';status.dispatchEvent(new Event('input',{bubbles:true}));const pending=rows().length;status.value='';status.dispatchEvent(new Event('input',{bubbles:true}));verification.value='manual_verified';verification.dispatchEvent(new Event('input',{bubbles:true}));const verified=rows().length;verification.value='';verification.dispatchEvent(new Event('input',{bubbles:true}));const button=document.querySelector('#admin-users-table th[data-sort-key=offers] .sort-button');button.click();const asc=rows().map(r=>Number(r.cells[6].textContent)),sortSelected=rows().find(r=>r.cells[2].textContent.trim()==='check-b@example.test').querySelector('input').checked;button.click();const desc=rows().map(r=>Number(r.cells[6].textContent));return {searched,pending,verified,asc,desc,selectedA,onlyB,hiddenSelection,restored,sortSelected,buttons:[document.querySelector('#reset-selected-user').disabled,document.querySelector('#delete-selected-user').disabled]}")
    assert users_filter_sort['searched']==1 and users_filter_sort['pending']==1 and users_filter_sort['verified']==1 and users_filter_sort['asc']==sorted(users_filter_sort['asc']) and users_filter_sort['desc']==sorted(users_filter_sort['desc'],reverse=True) and users_filter_sort['selectedA'] and users_filter_sort['onlyB'] and users_filter_sort['hiddenSelection']==0 and users_filter_sort['restored'] and users_filter_sort['sortSelected'] and users_filter_sort['buttons']==[False,False],users_filter_sort
    users_network=wd('POST',f'/session/{session}/log',{'type':'performance'});users_urls=[json.loads(x['message'])['message']['params']['request']['url'] for x in users_network if json.loads(x['message'])['message']['method']=='Network.requestWillBeSent'];assert len([u for u in users_urls if '/api/admin/users.php' in u])==1,users_urls
    admin_action_security=js("const t=document.querySelector('meta[name=csrf-token]').content;return Promise.all([fetch('/api/admin/user-password-reset.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({userId:2})}).then(r=>r.status),fetch('/api/admin/users.php',{method:'DELETE',headers:{'Content-Type':'application/json'},body:JSON.stringify({userId:2})}).then(r=>r.status),fetch('/api/admin/user-password-reset.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':t},body:JSON.stringify({userId:2,email:'attacker@example.test'})}).then(r=>r.status),fetch('/api/admin/user-password-reset.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':t},body:JSON.stringify({userId:1})}).then(r=>r.status),fetch('/api/admin/users.php',{method:'DELETE',headers:{'Content-Type':'application/json','X-CSRF-Token':t},body:JSON.stringify({userId:1})}).then(r=>r.status)])")
    assert admin_action_security==[403,403,400,403,403],admin_action_security
    action_security_logs=wd('POST',f'/session/{session}/log',{'type':'browser'});assert not [entry for entry in action_security_logs if entry.get('level')=='SEVERE' and not any(path in entry.get('message','') for path in ('/api/admin/users.php','/api/admin/user-password-reset.php'))],action_security_logs
    capture_path=os.path.join(os.environ['CROSSCHAPP_TEST_DATA_DIR'],'mail-capture.jsonl')
    before_reset_mails=[] if not os.path.exists(capture_path) else [json.loads(x) for x in open(capture_path,encoding='utf-8') if x.strip()]
    blocked_reset=js("const row=[...document.querySelectorAll('#users-list tr')].find(r=>r.cells[2].textContent.trim()==='check-c@example.test'),userId=Number(row.querySelector('input[type=radio]').value),t=document.querySelector('meta[name=csrf-token]').content;return fetch('/api/admin/user-password-reset.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':t},body:JSON.stringify({userId})}).then(async r=>({status:r.status,...await r.json()}))")
    assert blocked_reset=={'status':409,'error':'email_not_verified','message':'Für dieses Konto kann kein Passwortreset versendet werden, weil die E-Mail-Adresse noch nicht bestätigt wurde.'},blocked_reset
    blocked_ui=js("const row=[...document.querySelectorAll('#users-list tr')].find(r=>r.cells[2].textContent.trim()==='check-c@example.test');row.querySelector('input[type=radio]').click();document.querySelector('#reset-selected-user').click();document.querySelector('#confirm-admin-reset-password').click();return true");time.sleep(.4)
    assert js("return document.querySelector('#admin-reset-password-message').textContent")==blocked_reset['message'];js("document.querySelector('#cancel-admin-reset-password').click()")
    blocked_mails=[] if not os.path.exists(capture_path) else [json.loads(x) for x in open(capture_path,encoding='utf-8') if x.strip()];assert len(blocked_mails)==len(before_reset_mails),blocked_mails
    blocked_reset_logs=wd('POST',f'/session/{session}/log',{'type':'browser'});assert not [entry for entry in blocked_reset_logs if entry.get('level')=='SEVERE' and '/api/admin/user-password-reset.php' not in entry.get('message','')],blocked_reset_logs
    js("const row=[...document.querySelectorAll('#users-list tr')].find(r=>r.cells[2].textContent.trim()==='check-b@example.test');row.querySelector('input[type=radio]').click()")
    wd('POST',f'/session/{session}/log',{'type':'performance'})
    reset_confirmation=js("document.querySelector('#reset-selected-user').click();return {open:document.querySelector('#admin-reset-password-dialog').open,name:document.querySelector('#admin-reset-password-confirmation').textContent,email:document.querySelector('#admin-reset-password-confirmation [data-admin-user-email]').textContent}")
    assert reset_confirmation['open'] and 'Bernd Beta' in reset_confirmation['name'] and reset_confirmation['email']=='check-b@example.test',reset_confirmation
    js("document.querySelector('#cancel-admin-reset-password').click()");cancel_reset_network=wd('POST',f'/session/{session}/log',{'type':'performance'});assert not [x for x in cancel_reset_network if '/api/admin/user-password-reset.php' in x.get('message','')],cancel_reset_network
    js("document.querySelector('#reset-selected-user').click();document.querySelector('#confirm-admin-reset-password').click()");time.sleep(.7)
    reset_success=js("return {message:document.querySelector('#admin-reset-password-message').textContent,confirmation:document.querySelector('#admin-reset-password-confirmation').hidden,sendHidden:document.querySelector('#confirm-admin-reset-password').hidden,close:document.querySelector('#cancel-admin-reset-password').textContent}")
    assert reset_success=={'message':'Der Link zum Zurücksetzen des Passworts wurde versendet.','confirmation':True,'sendHidden':True,'close':'Schließen'},reset_success
    after_reset_mails=[json.loads(x) for x in open(capture_path,encoding='utf-8') if x.strip()];assert len(after_reset_mails)==len(before_reset_mails)+1,after_reset_mails
    reset_mail=after_reset_mails[-1];assert reset_mail['to']=='check-b@example.test' and 'Passwort' in reset_mail['subject'];reset_match=re.search(r'https?://[^\s]+/\?reset=([A-Za-z0-9_-]+)',reset_mail['body']);assert reset_match,reset_mail
    reset_token=reset_match.group(1);go('/?reset='+reset_token)
    reset_page=js("return {heading:document.querySelector('.login-panel h2')?.textContent,form:!!document.querySelector('#reset-password-form'),cross:!!document.querySelector('#chapter-search-form')}");assert reset_page=={'heading':'Neues Passwort vergeben','form':True,'cross':False},reset_page
    wd('POST',f'/session/{session}/log',{'type':'performance'});js("const f=document.querySelector('#reset-password-form');f.password.value='kurz';f.password_confirmation.value='kurz';f.requestSubmit()");time.sleep(.2);short_network=wd('POST',f'/session/{session}/log',{'type':'performance'});short_posts=[x for x in short_network if '/api/auth/reset-password.php' in x.get('message','') and 'Network.requestWillBeSent' in x.get('message','')];assert len(short_posts)<=1 and 'mindestens 8 Zeichen' in js("return document.querySelector('#reset-message').textContent") and js("return !!document.querySelector('#reset-password-form')"),short_posts
    js("const f=document.querySelector('#reset-password-form');f.password.value='password-a';f.password_confirmation.value='password-b';f.requestSubmit()");time.sleep(.3);assert 'stimmen nicht überein' in js("return document.querySelector('#reset-message').textContent")
    reset_error_logs=wd('POST',f'/session/{session}/log',{'type':'browser'});assert not [entry for entry in reset_error_logs if entry.get('level')=='SEVERE' and '/api/auth/reset-password.php' not in entry.get('message','')],reset_error_logs
    js("const f=document.querySelector('#reset-password-form');f.password.value='reset-password-456';f.password_confirmation.value='reset-password-456';f.requestSubmit()");time.sleep(.4)
    assert js("return document.querySelector('#reset-message').textContent.includes('Passwort wurde erfolgreich geändert.')&&document.querySelector('#reset-message a').textContent==='Anmelden'&&!document.querySelector('#reset-password-form')")
    login('check-b@example.test','check-password-123');assert 'nicht korrekt' in js("return document.querySelector('#login-message').textContent")
    old_password_logs=wd('POST',f'/session/{session}/log',{'type':'browser'});assert not [entry for entry in old_password_logs if entry.get('level')=='SEVERE' and '/api/auth/login.php' not in entry.get('message','')],old_password_logs
    login('check-b@example.test','reset-password-456');assert js("return document.querySelector('meta[name=auth-status]').content")=='authenticated'
    js("document.querySelector('#logout-button').click()");time.sleep(.3);go('/?view=forgot');forgot_before=[json.loads(x) for x in open(capture_path,encoding='utf-8') if x.strip()];js("const f=document.querySelector('#forgot-password-form');f.email.value='check-a@example.test';f.requestSubmit()");time.sleep(.5);forgot_after=[json.loads(x) for x in open(capture_path,encoding='utf-8') if x.strip()];assert len(forgot_after)==len(forgot_before)+1 and forgot_after[-1]['to']=='check-a@example.test' and '?reset=' in forgot_after[-1]['body']
    login('admin','admin');js("document.querySelector('#users-panel').open=true;document.querySelector('#users-panel').dispatchEvent(new Event('toggle'))");time.sleep(.5)
    wd('POST',f'/session/{session}/log',{'type':'performance'});delete_confirmation=js("const row=[...document.querySelectorAll('#users-list tr')].find(r=>r.cells[2].textContent.trim()==='check-b@example.test');row.querySelector('input[type=radio]').click();document.querySelector('#delete-selected-user').click();return {open:document.querySelector('#admin-delete-user-dialog').open,text:document.querySelector('#admin-delete-user-confirmation').textContent,count:document.querySelectorAll('#users-list tr').length}");assert delete_confirmation['open'] and 'Bernd Beta' in delete_confirmation['text'] and delete_confirmation['count']==3,delete_confirmation
    js("document.querySelector('#cancel-admin-delete-user').click()");cancel_delete_network=wd('POST',f'/session/{session}/log',{'type':'performance'});assert not [x for x in cancel_delete_network if '/api/admin/users.php' in x.get('message','') and 'DELETE' in x.get('message','')],cancel_delete_network
    js("document.querySelector('#delete-selected-user').click();document.querySelector('#confirm-admin-delete-user').click()");time.sleep(.7)
    delete_success=js("return {message:document.querySelector('#admin-delete-user-message').textContent,count:document.querySelectorAll('#users-list tr').length,emails:[...document.querySelectorAll('#users-list tr')].map(r=>r.cells[2].textContent.trim()),selected:document.querySelectorAll('#users-list input:checked').length,buttons:[document.querySelector('#reset-selected-user').disabled,document.querySelector('#delete-selected-user').disabled],stats:[...document.querySelectorAll('#users-stats>div')].map(x=>x.textContent.trim())}")
    assert delete_success['message']=='Der Anwender wurde gelöscht.' and delete_success['count']==2 and 'check-b@example.test' not in delete_success['emails'] and delete_success['selected']==0 and delete_success['buttons']==[True,True] and '2 Anwender' in delete_success['stats'],delete_success
    js("document.querySelector('#cancel-admin-delete-user').click()")
    js("document.querySelector('#invitations-panel').open=true;document.querySelector('#invitations-panel').dispatchEvent(new Event('toggle'))");time.sleep(.6)
    admin_account=js("document.querySelector('#account-menu-trigger').click();document.querySelector('#open-my-account').click();return {open:document.querySelector('#my-account-dialog').open,deleteButton:!!document.querySelector('#open-delete-account')}");assert admin_account=={'open':True,'deleteButton':False},admin_account
    admin_delete=js("const t=document.querySelector('meta[name=csrf-token]').content;return fetch('/api/auth/account.php',{method:'DELETE',headers:{'X-CSRF-Token':t}}).then(r=>r.status)");assert admin_delete==403,admin_delete
    admin_account_security_logs=wd('POST',f'/session/{session}/log',{'type':'browser'});assert not [entry for entry in admin_account_security_logs if entry.get('level')=='SEVERE' and '/api/auth/account.php' not in entry.get('message','')],admin_account_security_logs
    js("document.querySelector('#close-my-account').click()");
    chapter=js("const s=document.querySelector('#invitation-search');s.value='Königsforst';s.dispatchEvent(new Event('input',{bubbles:true}));const r=document.querySelector('#invitation-chapter-results .chapter-picker-item');r.querySelector('input').click();return {row:r.textContent,selected:document.querySelector('#invitation-selected-chapter').textContent}")
    assert 'Testchapter Königsforst' in chapter['row'] and 'Overath' in chapter['row'] and 'Deutschland' in chapter['row'] and 'Testchapter Königsforst' in chapter['selected'],chapter
    smtp_privacy=js("return fetch('/api/admin/mail-settings.php').then(r=>r.json()).then(data=>({password:Object.prototype.hasOwnProperty.call(data,'smtpPassword')||JSON.stringify(data).includes('smtp_password'),configured:Object.prototype.hasOwnProperty.call(data,'smtpPasswordConfigured')}))")
    assert not smtp_privacy['password'],smtp_privacy
    js("document.querySelector('#logout-button').click()");time.sleep(.3)
    console=wd('POST',f'/session/{session}/log',{'type':'browser'});bad=[entry for entry in console if entry.get('level')=='SEVERE' and 'favicon' not in entry.get('message','')]
    assert not bad,bad
    performance=wd('POST',f'/session/{session}/log',{'type':'performance'});urls=[]
    for entry in performance:
        try:
            message=json.loads(entry['message'])['message']
            if message['method']=='Network.requestWillBeSent':urls.append(message['params']['request']['url'])
        except (KeyError,ValueError):pass
    assert not [url for url in urls if any(host in url for host in FORBIDDEN)],urls
    print(f'PASS Chromium Smoke: Guide-Bildbreiten {widths}, Suche, anonym/auth, Angebote, Gesuche, Kontakt, Admin und Network-Guard')
finally:
    wd('DELETE',f'/session/{session}')
