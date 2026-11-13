/** Screen, Excel and PDF consume one successfully filtered, branch-scoped snapshot. */
(() => {
    const config = JSON.parse(document.getElementById('reportConfig').textContent);
    const form = document.getElementById('reportFilters'), content = document.getElementById('reportContent');
    const excel = document.getElementById('reportExcel'), pdf = document.getElementById('reportPdf');
    const status = document.getElementById('reportStatus');
    let report = config.report, ready = true, request, revision = 0;
    const number = (value, digits = 2) => Number(value).toLocaleString('en-US', {minimumFractionDigits: digits, maximumFractionDigits: digits});
    const node = (tag, className = '', text) => {
        const element = document.createElement(tag); element.className = className;
        if (text !== undefined) element.textContent = String(text ?? '');
        return element;
    };
    const humanize = value => String(value ?? '').replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase());
    function badge(value) {
        const kind = ['paid','active','completed','received','selected_period'].includes(value) ? 'positive'
            : ['partial','partially_received','pending','unpaid','not_billed','balance_brought_forward'].includes(value) ? 'warning'
            : ['cancelled','voided','refunded'].includes(value) ? 'negative' : '';
        return node('span', 'report-badge ' + kind, humanize(value));
    }
    function heading(title, subtitle, count) {
        const header = node('div','report-table-heading'), titleBlock = node('div');
        titleBlock.append(node('h2','',title)); if (subtitle) titleBlock.append(node('p','',subtitle));
        header.append(titleBlock); if (count !== undefined) header.append(node('span','report-count',`${count} ${count === 1 ? 'record' : 'records'}`));
        return header;
    }
    function cell(value, column) {
        const td = node('td');
        if (typeof value === 'number') {
            const count = /orders in period/i.test(column), qty = /quantity|qty/i.test(column);
            td.className = 'report-number' + (/due|closing balance/i.test(column) && value > 0 ? ' report-due' : '');
            td.textContent = number(value, count ? 0 : qty ? 3 : 2);
        } else if (/status|^scope$/i.test(column)) td.append(badge(value));
        else { td.textContent = value ?? '—'; if (/^order$|purchase \/ bill|^receipt$|^bill$|^payment$|^entry$/i.test(column)) td.className = 'report-reference'; }
        return td;
    }
    function table(columns, rows, details = false) {
        const wrap = node('div','report-table-wrap'), tableElement = node('table','report-table');
        const head = node('thead'), header = node('tr'), body = node('tbody');
        columns.forEach((column, index) => {
            const th = node('th', rows.some(row => typeof row[index] === 'number') ? 'report-number' : '', column);
            th.scope = 'col'; header.append(th);
        });
        if (details) { const th = node('th','','Details'); th.scope = 'col'; header.append(th); }
        head.append(header); tableElement.append(head); tableElement.append(body); wrap.append(tableElement);
        if (!rows.length) { const tr = node('tr'), td = node('td','report-empty','No records match these filters.'); td.colSpan = columns.length + Number(details); tr.append(td); body.append(tr); }
        rows.forEach((row, index) => {
            const tr = node('tr'); row.forEach((value, i) => tr.append(cell(value,columns[i]))); body.append(tr);
            if (!details) return;
            const detail = report.details[report.rowKeys[index]], actionCell = node('td'), button = node('button','report-eye');
            const expanded = node('tr','report-detail-row'), expandedCell = node('td'), detailContent = node('div','report-detail-content');
            expanded.id = `report-detail-${index}`; expanded.hidden = true; expandedCell.colSpan = columns.length + 1;
            button.type = 'button'; button.setAttribute('aria-expanded','false'); button.setAttribute('aria-controls',expanded.id);
            button.setAttribute('aria-label',`View ${detail.title}`); button.title = `View ${detail.title}`;
            button.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
            let populated = false;
            button.addEventListener('click', () => {
                if (!populated) {
                    detailContent.append(node('h3','',detail.title));
                    detail.tables.forEach(data => { const card = node('section','report-card'); card.append(heading(data.title,'',data.rows.length)); card.append(table(data.columns,data.rows)); detailContent.append(card); });
                    populated = true;
                }
                expanded.hidden = !expanded.hidden; button.setAttribute('aria-expanded',String(!expanded.hidden));
            });
            actionCell.append(button); tr.append(actionCell); expandedCell.append(detailContent); expanded.append(expandedCell); body.append(expanded);
        });
        return wrap;
    }
    function totalRow(label, value, className) {
        const total = node('div',className); total.append(node('span','',label)); total.append(node('strong','',number(value))); return total;
    }
    function statement(section) {
        const card = node('section','report-card report-statement-section'); card.dataset.section = section.key;
        card.append(heading(section.label,section.subtitle));
        if (!section.groups.length) card.append(node('p','report-empty','No accounts match these filters.'));
        section.groups.forEach(group => {
            card.append(node('h3','report-group-title',group.label));
            group.categories.forEach(category => {
                card.append(node('h4','report-category-title',category.label));
                const wrap = node('div','report-table-wrap'), accounts = node('table','report-table'), body = node('tbody');
                accounts.setAttribute('aria-label',`${section.label}: ${category.label}`);
                category.rows.forEach(([code,name,value]) => {
                    const row = node('tr'), account = node('td'); account.append(node('span','report-account-code',code)); account.append(node('span','',name));
                    row.append(account); row.append(cell(value,'Balance')); body.append(row);
                });
                accounts.append(body); wrap.append(accounts); card.append(wrap);
            });
            card.append(totalRow(`Total ${group.label}`,group.total,'report-group-total'));
        });
        card.append(totalRow(`Total ${section.label}`,section.total,'report-section-total')); return card;
    }
    function reconciliation() {
        const block = node('section','report-reconciliation');
        if (report.type === 'balance-sheet') {
            block.append(totalRow('Total assets',report.summary.Assets,''));
            block.append(totalRow('Total liabilities & equity',report.summary.Liabilities + report.summary['Equity including earnings'],''));
            block.append(totalRow('Net income included in equity',report.netIncome,''));
            const note = node('div','report-reconcile-note'), difference = report.summary.Difference;
            note.append(node('span','',`Assets − liabilities − equity = ${number(difference)} USD`));
            note.append(node('span','report-badge ' + (Math.abs(difference) < .005 ? 'positive' : 'negative'), Math.abs(difference) < .005 ? 'Balanced' : 'Out of balance'));
            block.append(note);
        } else {
            block.append(totalRow('Total income',report.summary.Income,'')); block.append(totalRow('Total expenses',report.summary.Expenses,'')); block.append(totalRow('Net profit / (loss)',report.summary['Net profit'],''));
        }
        return block;
    }
    function render() {
        content.replaceChildren();
        if (report.sections) {
            const sections = Object.fromEntries(report.sections.map(section => [section.key,section]));
            const grid = node('div','report-statement-grid');
            if (report.type === 'income-statement') {
                content.append(statement(sections.cash)); grid.append(statement(sections.income)); grid.append(statement(sections.expenses));
            } else {
                const left = node('div','report-statement-column'), right = node('div','report-statement-column');
                left.append(statement(sections.assets)); right.append(statement(sections.liabilities)); right.append(statement(sections.equity)); grid.append(left); grid.append(right);
            }
            content.append(grid); content.append(reconciliation());
        } else {
            const card = node('section','report-card'); card.append(heading(report.type === 'suppliers' ? 'Purchase & bill activity' : report.type === 'customers' ? 'Customer accounts' : 'Report details',report.details ? 'Select the eye button to expand the details below a row.' : '',report.rows.length));
            card.append(table(report.columns,report.rows,Boolean(report.details))); content.append(card);
        }
        const summary = document.getElementById('reportSummary'); summary.replaceChildren();
        Object.entries(report.summary).forEach(([label,value]) => {
            const card = node('div','report-kpi'), valueNode = node('p','report-kpi-value',number(value,/^Orders in period$/.test(label) ? 0 : 2));
            valueNode.append(node('span','report-kpi-unit',/^Orders in period$/.test(label) ? 'ORDERS' : label === 'Units' ? 'UNITS' : 'USD'));
            card.append(node('p','report-kpi-label',label)); card.append(valueNode); summary.append(card);
        });
        document.getElementById('reportNotice').textContent = report.notice;
        status.className = ''; status.textContent = `${report.rows.length} ${report.sections ? (report.rows.length === 1 ? 'account' : 'accounts') : (report.rows.length === 1 ? 'record' : 'records')} shown`;
    }
    render();
    function dirty() { revision++; ready = false; excel.disabled = pdf.disabled = true; status.textContent = 'Filters changed · apply to refresh'; }
    form.addEventListener('input',dirty); form.addEventListener('change',dirty);
    // Select2 emits jQuery change events, which do not always bubble as native events.
    if (window.jQuery) window.jQuery(form).on('change','select',dirty);
    form.addEventListener('submit',async event => {
        event.preventDefault(); if (!form.reportValidity()) return;
        request?.abort(); const controller = new AbortController(), requestedRevision = revision; request = controller;
        ready = false; excel.disabled = pdf.disabled = true; status.className = ''; status.textContent = 'Loading report…'; content.setAttribute('aria-busy','true');
        try {
            const result = await erp.web(config.dataUrl + '?' + new URLSearchParams(new FormData(form)), {signal:controller.signal});
            if (request !== controller || revision !== requestedRevision) return;
            report = result.data; render(); ready = true;
        } catch (error) {
            if (error.name !== 'AbortError' && request === controller) { status.className = 'report-error'; status.textContent = 'Could not apply filters. Previous results remain visible; exports are disabled.'; }
        } finally { if (request === controller) { excel.disabled = pdf.disabled = !ready; content.setAttribute('aria-busy','false'); } }
    });
    const day = date => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
    document.querySelectorAll('[data-report-range]').forEach(button => button.addEventListener('click',() => {
        const end = new Date(), start = new Date();
        if (button.dataset.reportRange === 'yesterday') { start.setDate(start.getDate()-1); end.setDate(end.getDate()-1); }
        if (button.dataset.reportRange === 'week') start.setDate(start.getDate()-(start.getDay()+6)%7);
        if (button.dataset.reportRange === 'month') start.setDate(1);
        form.elements.date_from.value = day(start); form.elements.date_to.value = day(end); dirty(); form.requestSubmit();
    }));
    const scope = () => `${report.filters.date_from} through ${report.filters.date_to} · ${report.parties ? 'Party: ' + (report.parties.find(p => String(p.id) === report.filters.party_id)?.name || 'All') + ' · ' : ''}Search: ${report.filters.q || 'all'} · ${report.notice}`;
    const safe = value => typeof value === 'string' && /^[=+@-]/.test(value) ? "'" + value : value;
    function excelHeader(row) { row.font = {bold:true,color:{argb:'FFFFFFFF'}}; row.fill = {type:'pattern',pattern:'solid',fgColor:{argb:'FF1F416D'}}; row.height = 24; }
    function excelTable(sheet,columns,rows) { excelHeader(sheet.addRow(columns)); rows.forEach(values => { const row = sheet.addRow(values.map(safe)); row.eachCell((cell,index) => { if (typeof cell.value === 'number') { cell.numFmt = /quantity|qty/i.test(columns[index-1]) ? '#,##0.000;[Red](#,##0.000)' : /orders in period/i.test(columns[index-1]) ? '#,##0' : '#,##0.00;[Red](#,##0.00)'; cell.alignment = {horizontal:'right'}; } }); }); }
    excel.addEventListener('click',async () => {
        if (!ready) return; excel.disabled = true;
        try {
            const snapshot = report, book = new ExcelJS.Workbook(), sheet = book.addWorksheet('Report');
            sheet.addRow([safe(snapshot.title)]).font = {bold:true,size:18}; sheet.addRow([safe(scope())]);
            Object.entries(snapshot.summary).forEach(([label,value]) => { const row = sheet.addRow([safe(label),value]); row.getCell(2).numFmt = '#,##0.00'; });
            sheet.addRow([]); excelTable(sheet,snapshot.columns,snapshot.rows); sheet.views = [{state:'frozen',ySplit:Object.keys(snapshot.summary).length+4}];
            if (snapshot.sections) {
                const statementSheet = book.addWorksheet('Statement'); statementSheet.addRow([snapshot.title]).font = {bold:true,size:18}; statementSheet.addRow([safe(scope())]);
                snapshot.sections.forEach(section => {
                    statementSheet.addRow([]); excelHeader(statementSheet.addRow([section.label]));
                    section.groups.forEach(group => { statementSheet.addRow([safe(group.label)]).font = {bold:true}; group.categories.forEach(category => { statementSheet.addRow([safe(category.label)]).font = {italic:true}; excelTable(statementSheet,['Code','Account','Balance (USD)'],category.rows); }); statementSheet.addRow([safe('Total '+group.label),'',group.total]).font = {bold:true}; });
                    statementSheet.addRow(['Total '+section.label,'',section.total]).font = {bold:true};
                });
                Object.entries(snapshot.summary).forEach(([label,value]) => statementSheet.addRow([label,'',value]));
                if (snapshot.type === 'balance-sheet') statementSheet.addRow(['Total liabilities & equity','',snapshot.summary.Liabilities+snapshot.summary['Equity including earnings']]);
            }
            if (snapshot.details) {
                const detailSheet = book.addWorksheet('Details'); detailSheet.addRow([snapshot.title+' — details']).font = {bold:true,size:18}; detailSheet.addRow([safe(scope())]);
                Object.values(snapshot.details).forEach(detail => { detailSheet.addRow([]); detailSheet.addRow([safe(detail.title)]).font = {bold:true,size:14}; detail.tables.forEach(data => { detailSheet.addRow([safe(data.title)]).font = {bold:true}; excelTable(detailSheet,data.columns,data.rows); if (!data.rows.length) detailSheet.addRow(['No records']); detailSheet.addRow([]); }); });
            }
            book.worksheets.forEach(worksheet => {
                worksheet.columns.forEach((column,index) => column.width = index === 1 ? 36 : 24);
                const lastColumn = Math.max(3,worksheet.columnCount);
                worksheet.mergeCells(1,1,1,lastColumn); worksheet.mergeCells(2,1,2,lastColumn);
                worksheet.getRow(2).alignment = {wrapText:true,vertical:'top'}; worksheet.getRow(2).height = lastColumn <= 3 ? 85 : 52;
                worksheet.eachRow(row => row.eachCell(cell => { if (typeof cell.value === 'number' && !cell.numFmt) cell.numFmt = '#,##0.00;[Red](#,##0.00)'; }));
                worksheet.pageSetup = {orientation:'landscape',fitToPage:true,fitToWidth:1,fitToHeight:0};
            });
            const bytes = await book.xlsx.writeBuffer(), url = URL.createObjectURL(new Blob([bytes],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
            const link = document.createElement('a'); link.href = url; link.download = snapshot.type+'.xlsx'; link.click(); setTimeout(() => URL.revokeObjectURL(url),10000);
        } catch (_) { erp.toast('Excel export failed.','error'); } finally { excel.disabled = !ready; }
    });
    function pdfTable(columns,rows) {
        return {margin:[0,6,0,15],table:{headerRows:1,widths:columns.map(() => '*'),body:[columns.map(text => ({text,bold:true,fillColor:'#edf3fb',color:'#345174'})),...(rows.length ? rows : [columns.map((_,index) => index ? '' : 'No records')]).map(row => row.map((value,index) => ({text:typeof value === 'number' ? number(value,/quantity|qty/i.test(columns[index]) ? 3 : /orders in period/i.test(columns[index]) ? 0 : 2) : String(value ?? ''),alignment:typeof value === 'number' ? 'right' : 'left'})))]},layout:'lightHorizontalLines'};
    }
    pdf.addEventListener('click',() => {
        if (!ready) return; pdf.disabled = true;
        try {
            const body = [{text:report.title,fontSize:20,bold:true,color:'#17365c'},{text:scope(),fontSize:8,color:'#71829e',margin:[0,8,0,14]}];
            Object.entries(report.summary).forEach(([label,value]) => body.push({text:`${label}: ${number(value)} USD`,bold:true,margin:[0,0,0,4]}));
            if (report.sections) {
                report.sections.forEach(section => {
                    body.push({text:section.label,fontSize:15,bold:true,color:'#254b78',margin:[0,18,0,4]});
                    body.push({text:section.subtitle,fontSize:8,color:'#71829e'});
                    section.groups.forEach(group => { body.push({text:group.label,bold:true,margin:[0,12,0,4]}); group.categories.forEach(category => { body.push({text:category.label,fontSize:9,color:'#71829e'}); body.push(pdfTable(['Code','Account','Balance (USD)'],category.rows)); }); body.push({text:`Total ${group.label}: ${number(group.total)}`,bold:true,alignment:'right'}); });
                    body.push({text:`Total ${section.label}: ${number(section.total)} USD`,fontSize:12,bold:true,alignment:'right',margin:[0,10,0,10]});
                });
                if (report.type === 'balance-sheet') body.push({text:`Total liabilities & equity: ${number(report.summary.Liabilities+report.summary['Equity including earnings'])} USD · ${Math.abs(report.summary.Difference)<.005?'Balanced':'Out of balance'}`,bold:true,margin:[0,14,0,0]});
                else body.push({text:`Total income: ${number(report.summary.Income)} · Total expenses: ${number(report.summary.Expenses)} · Net profit / (loss): ${number(report.summary['Net profit'])} USD`,bold:true,margin:[0,14,0,0]});
            } else body.push(pdfTable(report.columns,report.rows));
            Object.values(report.details || {}).forEach(detail => { body.push({text:detail.title,fontSize:13,bold:true,margin:[0,18,0,8]}); detail.tables.forEach(data => { body.push({text:data.title,bold:true,margin:[0,8,0,3]}); body.push(pdfTable(data.columns,data.rows)); }); });
            pdfMake.createPdf({pageSize:'A4',pageOrientation:'landscape',pageMargins:[30,30,30,35],defaultStyle:{fontSize:9},footer:(page,pages) => ({text:`${page} / ${pages}`,fontSize:8,color:'#71829e',alignment:'right',margin:[0,8,30,0]}),content:body}).download(report.type+'.pdf');
        } catch (_) { erp.toast('PDF export failed.','error'); } finally { pdf.disabled = !ready; }
    });
})();
