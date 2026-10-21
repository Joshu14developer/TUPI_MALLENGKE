<?php
/* ============================================================
   REPORT A VENDOR  (vendor portal)
   ------------------------------------------------------------
   Outputs nothing unless the visitor is a signed-in vendor, so it is
   safe to include on any vendor page.

   Markup contract — the page only adds the trigger button:

       <button type="button" class="report-trigger"
               data-report-vendor="12"
               data-report-name="Ana Cruz — Stall A-1">Report</button>

       …then, once per page:
       <?php require __DIR__ . '/../includes/report_modal.php'; ?>

   A page that lists vendors some other way (a tooltip, a drawer) can
   skip the button and call window.openReport(id, name) instead.
   ============================================================ */

if (!defined('ML_APP_ROOT')) define('ML_APP_ROOT', dirname(__DIR__));

require_once ML_APP_ROOT . '/config/reports.php';

/* Signed out, or not a vendor: no report button anywhere on the page. */
$reporter = report_reporter($conn);
if (!$reporter) return;

/* The endpoint lives at the app root; vendor pages sit one level down. */
$appDir  = str_replace('\\', '/', dirname(realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: __DIR__));
$rootDir = str_replace('\\', '/', ML_APP_ROOT);
$apiUrl  = $appDir === $rootDir ? 'report_api.php' : '../report_api.php';
?>
<style>
.report-trigger{
    display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:999px;
    border:1.5px solid var(--line,#e2e8f0);background:#fff;color:var(--muted,#64748b);
    font:inherit;font-size:11.5px;font-weight:800;cursor:pointer;white-space:nowrap;
    transition:all .2s ease;
}
.report-trigger:hover{border-color:#fca5a5;background:#fef2f2;color:#b91c1c;transform:translateY(-1px);}

.report-backdrop{position:fixed;inset:0;z-index:900;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(2,44,34,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);}
.report-backdrop.open{display:flex;}
.report-modal{width:100%;max-width:520px;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 28px 60px rgba(2,44,34,.28);animation:reportIn .3s cubic-bezier(.34,1.56,.64,1);}
@keyframes reportIn{from{opacity:0;transform:translateY(14px) scale(.97)}to{opacity:1;transform:none}}
.report-head{padding:20px 22px;background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;display:flex;align-items:flex-start;gap:12px;}
.report-head i{width:38px;height:38px;border-radius:12px;background:rgba(255,255,255,.2);display:grid;place-items:center;font-size:15px;flex-shrink:0;}
.report-head h3{font-size:15.5px;font-weight:800;letter-spacing:-.2px;}
.report-head p{font-size:12px;opacity:.9;margin-top:2px;}

.report-body{padding:20px 22px;display:flex;flex-direction:column;gap:14px;}
.report-field{display:flex;flex-direction:column;gap:6px;}
.report-field label{font-size:11.5px;font-weight:800;color:#475569;letter-spacing:.02em;display:flex;align-items:center;gap:5px;}
.report-field .req{color:#dc2626;}
.report-field select,.report-field textarea{
    width:100%;padding:11px 13px;border:1.5px solid #e2e8f0;border-radius:11px;
    font-family:inherit;font-size:13.5px;color:#0f172a;background:#fff;outline:none;transition:all .2s;
}
.report-field textarea{min-height:110px;resize:vertical;line-height:1.55;}
.report-field select:focus,.report-field textarea:focus{border-color:#f87171;box-shadow:0 0 0 4px rgba(239,68,68,.12);}
.report-field select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:36px;
    background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23b91c1c' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat:no-repeat;background-position:right 12px center;background-size:13px;}
.report-hint{font-size:11px;color:#64748b;line-height:1.45;}
.report-msg{display:none;padding:11px 14px;border-radius:11px;font-size:12.5px;font-weight:700;line-height:1.5;}
.report-msg.err{display:block;background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b;}
.report-msg.ok{display:block;background:#ecfdf5;border:1.5px solid #a7f3d0;color:#064e3b;}

.report-foot{padding:0 22px 20px;display:flex;gap:9px;}
.report-btn{flex:1;padding:12px;border-radius:12px;font:inherit;font-size:13.5px;font-weight:800;cursor:pointer;border:none;transition:all .2s;}
.report-btn.ghost{background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;}
.report-btn.ghost:hover{background:#f1f5f9;}
.report-btn.danger{background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;box-shadow:0 6px 18px rgba(239,68,68,.32);}
.report-btn.danger:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(239,68,68,.42);}
.report-btn[disabled]{opacity:.6;cursor:not-allowed;transform:none;}
@media(max-width:560px){.report-foot{flex-direction:column-reverse;}}
</style>

<div class="report-backdrop" id="reportBackdrop" aria-hidden="true">
    <div class="report-modal" role="dialog" aria-modal="true" aria-labelledby="reportTitle">
        <div class="report-head">
            <i class="fas fa-flag"></i>
            <div>
                <h3 id="reportTitle">Report this vendor</h3>
                <p id="reportTarget">The market office reviews every report.</p>
            </div>
        </div>

        <form class="report-body" id="reportForm" novalidate>
            <input type="hidden" name="action" value="create" />
            <input type="hidden" name="vendor_id" id="reportVendorId" />

            <div class="report-field">
                <label for="reportReason">What are you reporting? <span class="req">*</span></label>
                <select name="reason" id="reportReason" required>
                    <option value="">— Choose a reason —</option>
                    <?php foreach (report_reasons() as $key => $meta): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="report-field">
                <label for="reportDetails">What happened? <span class="req">*</span></label>
                <textarea name="details" id="reportDetails" maxlength="1500" required
                          placeholder="Describe what you saw, and roughly when. The market office may ask you for more."></textarea>
                <span class="report-hint" id="reportCounter">0 / 1500 characters · at least 10</span>
            </div>

            <div class="report-msg" id="reportMsg"></div>
        </form>

        <div class="report-foot">
            <button type="button" class="report-btn ghost" id="reportCancel">Cancel</button>
            <button type="submit" form="reportForm" class="report-btn danger" id="reportSubmit">
                <i class="fas fa-paper-plane"></i>&nbsp; Send to the market office
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    const API = <?= json_encode($apiUrl) ?>;

    const backdrop = document.getElementById('reportBackdrop');
    if (!backdrop) return;

    const form    = document.getElementById('reportForm');
    const vendorI = document.getElementById('reportVendorId');
    const reason  = document.getElementById('reportReason');
    const details = document.getElementById('reportDetails');
    const msg     = document.getElementById('reportMsg');
    const counter = document.getElementById('reportCounter');
    const target  = document.getElementById('reportTarget');
    const submit  = document.getElementById('reportSubmit');
    const lastTrigger = { node: null };

    function say(text, kind) {
        msg.textContent = text;
        msg.className = 'report-msg' + (kind ? ' ' + kind : '');
    }

    function count() {
        const n = details.value.trim().length;
        counter.textContent = n + ' / 1500 characters' + (n < 10 ? ' · at least 10' : '');
    }

    function open(vendorId, name) {
        vendorI.value = vendorId || '';
        target.textContent = (name || 'This vendor') + ' — the market office reviews every report.';
        say('', '');
        count();
        backdrop.classList.add('open');
        backdrop.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setTimeout(() => reason.focus(), 60);
    }

    function close() {
        backdrop.classList.remove('open');
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        form.reset();
        if (lastTrigger.node) lastTrigger.node.focus();
    }

    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('.report-trigger');
        if (trigger) {
            e.preventDefault();
            lastTrigger.node = trigger;
            open(trigger.dataset.reportVendor, trigger.dataset.reportName);
            return;
        }
        if (e.target === backdrop || e.target.id === 'reportCancel') close();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && backdrop.classList.contains('open')) close();
    });

    /* For pages that show a vendor without a button of their own. */
    window.openReport = function (vendorId, name) {
        lastTrigger.node = null;
        open(vendorId, name);
    };

    details.addEventListener('input', count);

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        say('', '');

        /* Checked here as well as server side, so an empty box never
           makes the round trip. */
        if (!reason.value) { say('Choose what you are reporting.', 'err'); reason.focus(); return; }
        if (details.value.trim().length < 10) {
            say('Please describe what happened (at least 10 characters).', 'err');
            details.focus();
            return;
        }

        submit.disabled = true;
        const original = submit.innerHTML;
        submit.innerHTML = '<i class="fas fa-spinner fa-spin"></i>&nbsp; Sending…';

        const body = new URLSearchParams(new FormData(form));

        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The market office could not be reached.' }; }); })
            .then(function (data) {
                if (data && data.ok) {
                    say('Thank you — the market office has your report.', 'ok');
                    submit.disabled = true;
                    submit.innerHTML = '<i class="fas fa-check"></i>&nbsp; Report sent';
                    details.value = '';
                    if (lastTrigger.node) lastTrigger.node.remove();
                    return;
                }
                say((data && data.error) || 'Something went wrong.', 'err');
                submit.disabled = false;
                submit.innerHTML = original;
            })
            .catch(function () {
                say('The market office could not be reached. Please try again.', 'err');
                submit.disabled = false;
                submit.innerHTML = original;
            });
    });
})();
</script>