#!/usr/bin/env python3

import base64
import datetime
import json
import os
import pathlib
import time
import urllib.request

DRIVER = os.environ.get('CROSSCHAPP_WEBDRIVER_URL', 'http://127.0.0.1:9519')
APP = os.environ.get('CROSSCHAPP_TEST_BASE_URL', 'http://127.0.0.1:18082')
OUTPUT = pathlib.Path(__file__).resolve().parent / 'screenshots' / 'crosschapp-user'


def webdriver(method, path, payload=None):
    request = urllib.request.Request(
        DRIVER + path,
        data=None if payload is None else json.dumps(payload).encode(),
        method=method,
        headers={'Content-Type': 'application/json'},
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.loads(response.read())['value']


session = webdriver('POST', '/session', {'capabilities': {'alwaysMatch': {
    'browserName': 'chrome',
    'goog:chromeOptions': {'args': ['--headless', '--no-sandbox', '--disable-gpu', '--window-size=1440,1000']},
    'goog:loggingPrefs': {'browser': 'ALL'},
}}})['sessionId']
webdriver('POST', f'/session/{session}/window/rect', {'width': 1440, 'height': 1200})


def js(source):
    return webdriver('POST', f'/session/{session}/execute/sync', {'script': source, 'args': []})


def go(path):
    webdriver('POST', f'/session/{session}/url', {'url': APP + path})
    time.sleep(0.8)


def login():
    go('/?view=login')
    js("const f=document.querySelector('#login-form');f.login.value='anna.beispiel@example.test';f.password.value='check-password-123';f.requestSubmit()")
    time.sleep(1)


def capture(filename):
    js("window.scrollTo(0,0);document.documentElement.style.scrollBehavior='auto'")
    time.sleep(0.25)
    data = webdriver('GET', f'/session/{session}/screenshot')
    (OUTPUT / filename).write_bytes(base64.b64decode(data))


def console_errors():
    entries = webdriver('POST', f'/session/{session}/log', {'type': 'browser'})
    return [entry for entry in entries if entry.get('level') == 'SEVERE' and 'favicon' not in entry.get('message', '')]


try:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    login()

    go('/?view=crosschaptern')
    js("const f=document.querySelector('#chapter-search-form');f.location.value='Testort';f.querySelector('[data-limit=\"all\"]').click();f.requestSubmit()")
    for _ in range(40):
        time.sleep(0.25)
        if not js("return document.querySelector('#search-button').disabled"):
            break
    js("document.body.style.zoom='.82';const first=document.querySelector('.result-card details');if(first&&!first.open)first.open=true")
    capture('01-crosschapptern-chapter-finden.png')

    go('/?view=vertretung')
    target = datetime.date.today()
    target += datetime.timedelta(days=((2 - target.weekday()) % 7) or 7)
    display = target.strftime('%d.%m.%Y')
    js(f"const input=document.querySelector('#representation-date');input.value={json.dumps(display)};input.dispatchEvent(new Event('input',{{bubbles:true}}));document.querySelector('input[data-org-id=\"910002\"]')?.click();document.querySelector('input[data-org-id=\"910004\"]')?.click();document.body.style.zoom='.72'")
    time.sleep(0.5)
    capture('02-vertretung-anbieten.png')

    go('/?view=vertretung-finden')
    js("document.body.style.zoom='.76'")
    time.sleep(0.8)
    capture('03-vertretung-finden.png')

    js("document.body.style.zoom='1';document.querySelector('#account-menu-trigger').click();document.querySelector('#open-my-account').click()")
    time.sleep(0.5)
    capture('04-mein-konto-verifiziert.png')
    js("document.querySelector('#close-my-account').click()")

    button_exists = js("return !!document.querySelector('#dated-representations .contact-representation, #dated-representations button[data-contact-offer], #dated-representations button')")
    if button_exists:
        js("document.querySelector('#dated-representations .contact-representation, #dated-representations button[data-contact-offer], #dated-representations button').click()")
        time.sleep(0.6)
        if js("return document.querySelector('#representation-contact-dialog')?.open===true"):
            capture('05-kontaktieren-overlay.png')

    errors = console_errors()
    if errors:
        raise RuntimeError(f'Unerwartete Browserfehler: {errors}')
    print(json.dumps({'screenshots': sorted(path.name for path in OUTPUT.glob('*.png')), 'consoleErrors': 0}, ensure_ascii=False))
finally:
    webdriver('DELETE', f'/session/{session}')
