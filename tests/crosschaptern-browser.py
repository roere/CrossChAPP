#!/usr/bin/env python3
import json, time, urllib.error, urllib.request

BASE = 'http://127.0.0.1:9516'

def request(method, path, payload=None):
    req = urllib.request.Request(BASE + path, data=None if payload is None else json.dumps(payload).encode(), method=method, headers={'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req) as response:
            return json.loads(response.read())['value']
    except urllib.error.HTTPError as error:
        raise RuntimeError(error.read().decode()) from error

session = request('POST', '/session', {'capabilities': {'alwaysMatch': {'browserName': 'chrome', 'goog:chromeOptions': {'args': ['--headless', '--no-sandbox', '--disable-gpu', '--window-size=1440,1000']}, 'goog:loggingPrefs': {'browser': 'ALL', 'performance': 'ALL'}}}})['sessionId']

def script(source):
    return request('POST', f'/session/{session}/execute/sync', {'script': source, 'args': []})

try:
    request('POST', f'/session/{session}/url', {'url': 'http://localhost:8082/?view=crosschaptern'})
    time.sleep(.6)
    initial = script("return {nav:[...document.querySelectorAll('nav a')].map(x=>x.textContent.trim()),typeLabels:[...document.querySelectorAll('#chapter-search-form label,#chapter-search-form legend')].map(x=>x.textContent.trim()).filter(x=>x==='Typ'),typeInputs:document.querySelectorAll('#chapter-search-form [name=type],#chapter-search-form #type-filter').length,limit:document.querySelector('#search-limit').value,oldEyebrow:document.body.textContent.includes('Chaptertreffen entdecken'),heroTitle:[...document.querySelectorAll('main h1')].some(x=>x.textContent.trim()==='CrossChAPP'),hero:!!document.querySelector('.search-hero'),intro:document.querySelector('.page-intro-title')?.textContent.trim(),top:document.querySelector('.search-panel').getBoundingClientRect().top};")
    assert 'CrossChAPPtern' in initial['nav'] and not initial['typeLabels'] and initial['typeInputs'] == 0 and initial['limit'] == '10' and not initial['oldEyebrow'] and not initial['heroTitle'] and not initial['hero'] and initial['intro']=='Finde passende BNI-Chaptertreffen in deiner Nähe.' and initial['top']<260, initial
    request('POST',f'/session/{session}/window/rect',{'width':390,'height':844});time.sleep(.2);assert script('return document.documentElement.scrollWidth<=document.documentElement.clientWidth;')
    request('POST', f'/session/{session}/log', {'type': 'performance'})
    script("const f=document.querySelector('#chapter-search-form');document.querySelector('#search-location').value='51149 Köln';f.querySelector('input[name=days][value=Freitag]').click();f.querySelector('input[name=time][value=early]').click();f.querySelector('[data-limit=\"5\"]').click();f.requestSubmit();")
    for _ in range(40):
        time.sleep(.25)
        if script("return !document.querySelector('#search-button').disabled;"):
            break
    result = script("const cards=[...document.querySelectorAll('.result-card')];return {visible:!document.querySelector('#search-results').hidden,count:cards.length,heading:document.querySelector('#results-heading').textContent,typeFacts:[...document.querySelectorAll('.result-card dt')].filter(x=>x.textContent.trim()==='Typ').length,error:document.querySelector('#search-message').textContent};")
    assert result['visible'] and result['count'] <= 5 and result['typeFacts'] == 0 and not result['error'], result
    if result['count']:
        detail = script("const b=document.querySelector('.result-detail-toggle');b.click();return {expanded:b.getAttribute('aria-expanded'),typeFacts:[...document.querySelectorAll('.result-detail-panel dt')].filter(x=>x.textContent.trim()==='Typ').length};")
        assert detail == {'expanded': 'true', 'typeFacts': 0}, detail
        script("document.querySelector('#map-toggle').click();")
        time.sleep(.8)
        markers = script("return document.querySelectorAll('#results-map .leaflet-marker-icon').length;")
        assert markers == result['count'], (markers, result)
    logs = request('POST', f'/session/{session}/log', {'type': 'browser'})
    assert not [entry for entry in logs if entry.get('level') == 'SEVERE'], logs
    performance = request('POST', f'/session/{session}/log', {'type': 'performance'})
    urls = []
    for entry in performance:
        try:
            message = json.loads(entry['message'])['message']
            if message['method'] == 'Network.requestWillBeSent': urls.append(message['params']['request']['url'])
        except (KeyError, ValueError):
            pass
    assert not [url for url in urls if 'bni.de' in url], urls
    print(f"PASS Chromium: CrossChAPPtern ohne Typfilter/-anzeige, lokale Suche mit {result['count']} Treffern und Karte")
finally:
    request('DELETE', f'/session/{session}')
