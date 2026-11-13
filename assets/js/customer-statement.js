/** Read-only statement filters and exports share the same complete filtered result set. */
(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('statementConfig').textContent);
    const form = document.getElementById('statementFilters');
    const status = document.getElementById('statementStatus');
    const excelButton = document.getElementById('exportExcel');
    const pdfButton = document.getElementById('exportPdf');
    const money = value => Number(value).toFixed(2);
    const textRenderer = $.fn.dataTable.render.text();
    const table = erp.initDataTable('#statementTable', { pageLength: 25, responsive: false, searching: false, order: [], columns: [
        { data: 'date', render: textRenderer }, { data: 'type', render: textRenderer }, { data: 'ref', render: textRenderer },
        ...['debit', 'credit', 'balance'].map(data => ({ data, className: 'text-right', render: money }))
    ] });
    let statement = config.statement;
    let requestController;
    let timer;
    let loading = false;
    let exportReady = true;
    function render() {
        table.clear().rows.add(statement.entries).draw();
        Object.entries(statement.summary).forEach(([key, value]) => document.getElementById('summary-' + key).textContent = 'USD ' + money(value));
        status.textContent = `${statement.entries.length} transactions · Positive balance = customer owes; negative = store credit`;
    }
    async function load() {
        requestController?.abort();
        const controller = new AbortController();
        requestController = controller;
        loading = true; exportReady = false; excelButton.disabled = pdfButton.disabled = true;
        status.textContent = 'Loading statement…';
        try {
            statement = (await erp.web(config.dataUrl + '?' + new URLSearchParams(new FormData(form)), { signal: controller.signal })).data;
            render();
            exportReady = true;
        } catch (error) { if (error.name !== 'AbortError') status.textContent = 'Filters could not be applied. The previous results remain displayed.'; }
        finally { if (requestController === controller) { loading = false; excelButton.disabled = pdfButton.disabled = !exportReady; } }
    }
    form.addEventListener('submit', event => { event.preventDefault(); load(); });
    form.addEventListener('input', () => { exportReady = false; clearTimeout(timer); excelButton.disabled = pdfButton.disabled = true; timer = setTimeout(load, 300); });
    document.getElementById('clearStatementFilters').addEventListener('click', () => { [...form.elements].forEach(element => element.value = ''); clearTimeout(timer); load(); });
    // Export libraries are loaded only on demand so statement navigation stays light.
    let pdfLibrariesReady = typeof pdfMake !== 'undefined';
    function loadScript(url) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script'); script.src = url;
            script.onload = resolve; script.onerror = () => { script.remove(); reject(new Error('Export library failed to load.')); };
            document.head.append(script);
        });
    }
    async function prepareExcel() {
        if (typeof ExcelJS === 'undefined') await loadScript(config.excelUrl);
    }
    async function preparePdf() {
        if (pdfLibrariesReady) return;
        if (typeof pdfMake === 'undefined') await loadScript(config.pdfUrl);
        await loadScript(config.fontsUrl);
        pdfLibrariesReady = true;
    }
    const headers = ['Date', 'Type', 'Reference', 'Charges (USD)', 'Payments / credits (USD)', 'Running balance (USD)'];
    const rows = () => statement.entries.map(entry => [entry.date, entry.type, entry.ref || '', Number(entry.debit), Number(entry.credit), Number(entry.balance)]);
    const scope = () => `From ${statement.filters.date_from || 'beginning'} through ${statement.filters.date_to || 'latest'} · Type: ${statement.filters.type || 'all'} · Reference: ${statement.filters.q || 'all'}`;
    const filename = () => 'customer-statement-' + new Date().toISOString().slice(0, 10);
    function download(bytes, name, type) {
        const url = URL.createObjectURL(new Blob([bytes], { type }));
        const link = document.createElement('a'); link.href = url; link.download = name; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    }
    excelButton.addEventListener('click', async () => {
        if (loading || !exportReady) return;
        excelButton.disabled = true; excelButton.textContent = 'Preparing Excel…';
        try {
            await prepareExcel();
            const workbook = new ExcelJS.Workbook();
            const sheet = workbook.addWorksheet('Statement');
            sheet.columns = headers.map((_, index) => ({ width: index === 2 ? 30 : 24 }));
            sheet.addRow([statement.party + ' — Account statement']); sheet.addRow([scope()]);
            Object.entries(statement.summary).forEach(([key, value]) => sheet.addRow([key.replaceAll('_', ' '), value]));
            sheet.addRow([]); const header = sheet.addRow(headers); header.font = { bold: true };
            rows().forEach(row => sheet.addRow(row));
            [4,5,6].forEach(index => sheet.getColumn(index).numFmt = '0.00');
            download(await workbook.xlsx.writeBuffer(), filename() + '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            erp.toast('Excel statement exported.');
        } catch (error) { console.error('Statement Excel export failed', error); erp.toast('The Excel export could not be created.', 'error'); }
        finally { excelButton.disabled = false; excelButton.textContent = 'Export Excel'; }
    });
    pdfButton.addEventListener('click', async () => {
        if (loading || !exportReady) return;
        pdfButton.disabled = true; pdfButton.textContent = 'Preparing PDF…';
        try {
            await preparePdf();
            pdfMake.createPdf({ pageSize: 'A4', pageOrientation: 'landscape', defaultStyle: { fontSize: 9 }, content: [
                { text: statement.party + ' — Account statement', fontSize: 17, bold: true, margin: [0,0,0,10] },
                { text: scope(), margin: [0,0,0,12] },
                ...Object.entries(statement.summary).map(([key,value]) => ({ text: key.replaceAll('_',' ') + ': USD ' + money(value), margin: [0,0,0,4] })),
                { text: 'Positive balance = customer owes; negative = store credit', margin: [0,8,0,12] },
                { table: { headerRows: 1, widths: ['auto','auto','*','auto','auto','auto'], body: [headers.map(text => ({text,bold:true})), ...rows().map(row => row.map((value,index) => index > 2 ? {text:money(value),alignment:'right'} : String(value)))] }, layout: 'lightHorizontalLines' }
            ] }).download(filename() + '.pdf');
        } catch (error) { console.error('Statement PDF export failed', error); erp.toast('The PDF export could not be created.', 'error'); }
        finally { pdfButton.disabled = false; pdfButton.textContent = 'Export PDF'; }
    });
    render();
})();
