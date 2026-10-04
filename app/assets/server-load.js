(() => {
    'use strict';
    const panel = document.querySelector('#server-load-panel');
    if (!panel) return;
    let loading = false, runs = [];
    const fields = ['started_at_ms','duration_ms','candidate_notifications','subscriptions_targeted','delivery_attempts','success_count','failure_count','expired_subscription_count'];
    const sortFields = Object.fromEntries(fields.map(key => [key, {type: 'number', value: row => row[key]}]));
    const sort = window.CrossChappSort.bind(document.querySelector('#server-load-table'), renderRows);
    sort.key = 'started_at_ms'; sort.direction = 'descending';
    document.querySelector('#server-load-table th[data-sort-key=started_at_ms]').setAttribute('aria-sort','descending');
    const number = value => value === null || value === undefined ? 'Nicht verfügbar' : Number(value).toLocaleString('de-DE', {maximumFractionDigits: 2});
    const date = value => value ? new Intl.DateTimeFormat('de-DE', {timeZone:'Europe/Berlin',dateStyle:'short',timeStyle:'medium'}).format(new Date(value)) : 'Noch kein Heartbeat';
    function renderRows() {
        document.querySelector('#server-load-runs').replaceChildren(...window.CrossChappSort.sort(runs, sort, sortFields).map(row => {
            const tr = document.createElement('tr');
            for (const key of fields) { const cell = document.createElement('td'); cell.textContent = key==='started_at_ms' ? date(row[key]) : number(row[key]); tr.append(cell); }
            return tr;
        }));
        document.querySelector('#server-load-empty').hidden = runs.length > 0;
    }
    const cards = (selector, values) => document.querySelector(selector).replaceChildren(...values.map(([label,value]) => {
        const box=document.createElement('div'), strong=document.createElement('strong'), span=document.createElement('span');
        strong.className='stat-value';span.className='stat-label';strong.textContent=value;span.textContent=label;box.append(strong,document.createTextNode(' '),span);return box;
    }));
    function chart(key, points, valueKey, unit) {
        const svg=document.querySelector(`#server-load-${key}-chart`), maximum=Math.max(1,...points.map(point=>point[valueKey]));
        svg.querySelector('polyline').setAttribute('points',points.map((point,i)=>`${42+i/Math.max(1,points.length-1)*666},${190-point[valueKey]/maximum*178}`).join(' '));
        svg.querySelector('.chart-maximum').textContent=number(maximum);
        const time=new Intl.DateTimeFormat('de-DE',{timeZone:'Europe/Berlin',hour:'2-digit',minute:'2-digit'});
        svg.querySelector('.chart-start').textContent=points.length?time.format(new Date(points[0].time)):'--:--';
        svg.querySelector('.chart-end').textContent=points.length?time.format(new Date(points.at(-1).time)):'--:--';
        svg.querySelector('desc').textContent=`15-Minuten-Summen in ${unit}. Maximum ${number(maximum)}. Null bedeutet keine gemessenen Zustellversuche bzw. keine Verarbeitungszeit in diesem Zeitraum.`;
    }
    async function load() {
        if (loading || !panel.open) return;
        loading=true;const message=document.querySelector('#server-load-message');
        try {
            const response=await fetch('/api/admin/server-load.php'), data=await response.json();
            if(!response.ok)throw new Error(data.error||'Serverlast konnte nicht geladen werden.');
            document.querySelector('#server-load-scope').textContent=data.scope;
            document.querySelector('#server-load-status').textContent=data.status;
            const server=data.server, push=data.push;
            cards('#server-load-server', [['CPU-Auslastung','Nicht zuverlässig verfügbar'], ...[1,5,15].map((minutes,index)=>[`Load Average ${minutes} Minuten`,number(server.load_average?.[index])]),
                ['Container-Speicher',server.container_memory_bytes===null?'Nicht verfügbar':`${number(server.container_memory_bytes/1048576)} MiB`],['Container-Speicherlimit',server.container_memory_limit_bytes===null?'Nicht verfügbar':`${number(server.container_memory_limit_bytes/1048576)} MiB`],['PHP-Prozessspeicher',`${number(server.process_memory_bytes/1048576)} MiB`],['PHP-Prozessspitze',`${number(server.process_peak_bytes/1048576)} MiB`],['Letzter Worker-Heartbeat',date(server.worker_heartbeat)]]);
            const labels={runs:'Push-Läufe letzte 24 h',delivery_attempts:'Zustellversuche letzte 24 h',success_count:'Erfolgreiche Zustellungen',failure_count:'Fehlgeschlagene Zustellungen',expired_subscription_count:'Entfernte ungültige Geräte',subscriptions:'Registrierte Geräte',subscribed_users:'Benutzer mit Push',average_duration_ms:'Ø Dauer je Lauf (ms)',maximum_duration_ms:'Max. Dauer je Lauf (ms)',duration_ms:'Gesamte Verarbeitungszeit (ms)'};
            cards('#server-load-push',Object.entries(labels).map(([key,label])=>[label,number(push[key])]));
            chart('attempts',data.series,'delivery_attempts','Zustellversuchen');chart('duration',data.series,'duration_ms','ms');
            runs=data.runs;renderRows();message.textContent='';message.className='message';
        } catch(error) { message.textContent=error.message;message.className='message error'; }
        finally { loading=false; }
    }
    window.CrossChappServerLoad={load};
})();
