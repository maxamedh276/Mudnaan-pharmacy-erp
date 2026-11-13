<section id="qzSigningSettings" class="mt-8 border-t border-slate-200 pt-6" data-url="<?= esc(site_url('web/printing/signing'), 'attr') ?>">
    <h3 class="font-semibold text-sm">QZ signing for this computer</h3>
    <p class="text-sm text-slate-500 mt-2">Configure remembered approval for the browser you are using. Other organizations and computers have separate signing files.</p>
    <p class="text-xs text-slate-500 mt-3">Computer ID: <code id="qzComputerId" class="break-all"></code></p>
    <p id="qzSigningStatus" role="status" class="text-sm my-4">Checking signing setup…</p>
    <details class="text-sm text-slate-600 mb-5">
        <summary class="cursor-pointer font-medium">How to generate the free local certificate</summary>
        <ol class="list-decimal pl-5 mt-3 space-y-2">
            <li>On this cashier PC, open QZ Tray → Advanced → Site Manager → + → Create New.</li>
            <li>Follow QZ's prompts to generate and install the demo certificate. Only approve installation on a computer you administer.</li>
            <li>Choose the certificate and private key from the “QZ Tray Demo Cert” folder on your Desktop below.</li>
            <li>Save, reload the page, then use Test QZ printer. When QZ shows the trusted certificate, select Remember this decision and Allow.</li>
        </ol>
        <p class="mt-3">Demo certificates are intended for development and trust only the QZ installation where they were generated. Uploading these files does not install trust on other PCs. Browser profiles have separate computer IDs; clearing browser storage requires setup again.</p>
    </details>
    <form id="qzSigningForm" class="grid sm:grid-cols-2 gap-4 text-sm">
        <label class="font-medium">Certificate (digital-certificate.txt)
            <input type="file" name="certificate" accept=".txt,.crt,.pem" required class="mt-2 block w-full border rounded-lg p-2">
        </label>
        <label class="font-medium">Matching private key (private-key.pem)
            <input type="file" name="private_key" accept=".pem" required class="mt-2 block w-full border rounded-lg p-2">
        </label>
        <p class="sm:col-span-2 text-xs text-slate-500">The private key is uploaded to protected server storage. It cannot be downloaded from Settings and is never placed in public assets or Git. Use HTTPS on the production website.</p>
        <div class="sm:col-span-2"><button type="submit" disabled class="bg-brand-600 text-white px-4 py-3 rounded-xl font-semibold disabled:opacity-50">Save signing files for this computer</button></div>
    </form>
</section>
