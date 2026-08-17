#!/usr/bin/env python3
import json
import time
import urllib.request

BASE = "http://127.0.0.1:9516"


def request(method, path, payload=None):
    data = None if payload is None else json.dumps(payload).encode()
    req = urllib.request.Request(BASE + path, data=data, method=method, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req) as response:
        return json.loads(response.read())["value"]


session = request("POST", "/session", {"capabilities": {"alwaysMatch": {"browserName": "chrome", "goog:chromeOptions": {"args": ["--headless", "--no-sandbox", "--disable-gpu", "--window-size=1440,1000"]}}}})["sessionId"]


def script(source):
    return request("POST", f"/session/{session}/execute/sync", {"script": source, "args": []})


try:
    request("POST", f"/session/{session}/url", {"url": "http://localhost:8082/?view=vertretung"})
    time.sleep(1)
    initial = script("return {rows:document.querySelectorAll('#representation-list tr').length,min:document.querySelector('#representation-date').min,title:document.querySelector('h1').textContent};")
    assert initial["rows"] == 881 and initial["min"] and "Vertretung für folgende Chapter" in initial["title"]

    past = script("const i=document.querySelector('#representation-date');i.value='2000-01-01';document.querySelector('#add-representation-date').click();return document.querySelector('#representation-date-message').textContent;")
    assert "heutiges oder zukünftiges" in past

    dates = script("const base=new Date(document.querySelector('#representation-date').min+'T12:00:00');const next=(from,d)=>{const x=new Date(from);x.setDate(x.getDate()+((d-x.getDay()+7)%7));return x};const friday=next(base,5);const monday=new Date(friday);monday.setDate(friday.getDate()+3);return {friday:friday.toISOString().slice(0,10),monday:monday.toISOString().slice(0,10)};")
    for value in [dates["friday"], dates["friday"], dates["monday"]]:
        script(f"const i=document.querySelector('#representation-date');i.value='{value}';document.querySelector('#add-representation-date').click();")
    chips = script("return [...document.querySelectorAll('.date-chip')].map(x=>x.textContent.trim());")
    assert len(chips) == 2 and chips[0].startswith("Fr") and chips[1].startswith("Mo")

    all_dates = script("const a=document.querySelector('#representation-all-dates');a.click();return {chips:document.querySelectorAll('.date-chip').length,disabled:[...document.querySelectorAll('.date-chip button')].every(x=>x.disabled),classed:[...document.querySelectorAll('.date-chip')].every(x=>x.classList.contains('disabled'))};")
    assert all_dates == {"chips": 2, "disabled": True, "classed": True}
    script("document.querySelector('#representation-all-dates').click();document.querySelectorAll('.date-chip button')[1].click();")

    filtered = script("const set=(q,v)=>{const e=document.querySelector(q);e.value=v;e.dispatchEvent(new Event('input',{bubbles:true}))};set('#representation-country','DE');set('#representation-type','CHAPTER');return [...document.querySelectorAll('#representation-list tr')].every(r=>r.cells[3]?.textContent==='Deutschland'&&r.cells[4]?.textContent==='Chapter'&&r.cells[6]?.textContent==='Freitag');")
    assert filtered

    script("document.querySelector('#representation-location').value='51149 Köln';document.querySelector('#representation-radius').value='100';document.querySelector('#apply-representation-radius').click();")
    time.sleep(1)
    radius = script("return {message:document.querySelector('#representation-location-message').textContent,distances:[...document.querySelectorAll('#representation-list tr')].map(r=>r.cells[8]?.textContent).filter(Boolean)};")
    assert "aktiv" in radius["message"] and radius["distances"] and all(float(value.replace(" km", "").replace(".", "").replace(",", ".")) <= 100 for value in radius["distances"])

    preserved = script("document.querySelector('#select-visible-representations').click();const before=document.querySelector('#representation-counts').textContent;const r=document.querySelector('#representation-radius');r.value='1';r.dispatchEvent(new Event('input',{bubbles:true}));return {before,after:document.querySelector('#representation-counts').textContent,selected:document.querySelector('#representation-counts').textContent.match(/· (\\d+)/)[1]};")
    assert int(preserved["selected"]) > 0 and preserved["before"].split("·")[1] == preserved["after"].split("·")[1]

    restored = script("const before=document.querySelectorAll('#representation-list tr').length;document.querySelector('#representation-all-dates').click();const during=document.querySelectorAll('#representation-list tr').length;document.querySelector('#representation-all-dates').click();const after=document.querySelectorAll('#representation-list tr').length;document.querySelector('#clear-representations').click();return {before,during,after,counts:document.querySelector('#representation-counts').textContent};")
    assert restored["during"] >= restored["before"] and restored["after"] == restored["before"] and "0 Chapter ausgewählt" in restored["counts"]

    logs = request("POST", f"/session/{session}/log", {"type": "browser"})
    severe = [entry for entry in logs if entry.get("level") == "SEVERE"]
    assert not severe, severe
    print("PASS Chromium: Datum, Alle Daten, Filter, Radius und stabile Auswahl")
finally:
    request("DELETE", f"/session/{session}")
