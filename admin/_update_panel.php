<?php
/* ============================================================
   PERMIT & PAYMENT UPDATE PANEL  (admin side)
   ------------------------------------------------------------
   Private, one-to-one notices. The admin picks a vendor and an
   action; the message is built from that vendor's own record so
   the expiry date, amount and status are never typed by hand.
   Optionally the same submit also writes the new value onto
   vendors, so the office and the vendor never disagree.

   Expects from admin_notifications.php:
     $vendors[]        vendor rows for the picker
     $UPDATE_ACTIONS   permit_update_actions()
     $vendorJson       id => record summary, for the live preview
   $SMS_CFG          sms_settings(), for the text-message preview
   $SMS_READY        is the gateway usable right now?
   ============================================================ */
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v); }
}

/* Which inputs each action actually needs — the browser hides the rest. */
$ACTION_FIELDS = [
    'permit_valid'     => ['expiry', 'months', 'amount', 'or_number', 'notes'],
    'permit_expired'   => ['expiry', 'notes'],
    'permit_suspended' => ['notes'],
    'permit_pending'   => ['notes'],
    'payment_paid'     => ['amount', 'or_number', 'period', 'next_due', 'notes'],
    'payment_partial'  => ['amount', 'or_number', 'period', 'notes'],
    'payment_unpaid'   => ['amount', 'next_due', 'notes'],
];

$FIELD_LABELS = [
    'expiry'    => 'New expiry date',
    'months'    => 'Term (months)',
    'amount'    => 'Amount (₱)',
    'or_number' => 'OR Number',
    'period'    => 'Period covered',
    'next_due'  => 'Next due date',
    'notes'     => 'Extra note for the vendor',
];
?>
<style>
.up-card{background:#fff;border:1.5px solid var(--line);border-radius:18px;box-shadow:var(--sh-sm);overflow:hidden;margin-bottom:18px;}
.up-head{padding:18px 22px;border-bottom:1.5px solid var(--line-soft);display:flex;align-items:center;gap:12px;
    background:linear-gradient(180deg,#fffbeb,#fff);}
.up-head-icon{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#fbbf24,#d97706);display:grid;place-items:center;color:#fff;font-size:15px;box-shadow:0 5px 14px rgba(217,119,6,.3);flex-shrink:0;}
.up-head h2{font-size:15px;font-weight:800;color:var(--ink);}
.up-head p{font-size:12px;color:var(--muted);margin-top:1px;}
.up-body{padding:20px 22px;}

.up-grid{display:grid;grid-template-columns:minmax(0,340px) minmax(0,1fr);gap:18px;align-items:start;}
@media(max-width:900px){.up-grid{grid-template-columns:1fr;}}

/* The vendor's current record */
.rec{background:var(--green-50);border:1.5px solid var(--green-200);border-radius:14px;padding:14px 16px;margin-bottom:16px;}
.rec-top{display:flex;align-items:center;gap:11px;margin-bottom:12px;}
.rec-avatar{width:38px;height:38px;border-radius:11px;flex-shrink:0;display:grid;place-items:center;color:#fff;font-weight:800;font-size:13px;background:linear-gradient(135deg,#34d399,#059669);}
.rec-name{font-size:14px;font-weight:800;color:var(--ink);}
.rec-sub{font-size:11.5px;color:var(--muted);margin-top:2px;}
.rec-rows{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
@media(max-width:520px){.rec-rows{grid-template-columns:1fr;}}
.rec-row{background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 11px;}
.rec-row .k{font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;}
.rec-row .v{font-size:13px;font-weight:800;color:var(--ink);margin-top:3px;}
.rec-empty{font-size:12.5px;color:var(--muted);font-style:italic;}

.up-fields{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
@media(max-width:700px){.up-fields{grid-template-columns:1fr;}}
.up-fields .field{margin-bottom:0;}
/* The UA's "[hidden]{display:none}" loses to the author-level .field rule,
   so hidden fields would stay visible without this. */
.up-fields .field[hidden]{display:none}

/* Message preview */
.preview{margin-top:16px;border:1.5px solid var(--line);border-radius:14px;overflow:hidden;}
.preview-head{padding:9px 14px;background:var(--bg);border-bottom:1px solid var(--line);font-size:11px;font-weight:800;color:var(--muted);
    text-transform:uppercase;letter-spacing:.06em;display:flex;align-items:center;gap:7px;}
.preview-body{padding:15px 16px;font-size:13px;line-height:1.6;color:var(--ink-soft);white-space:pre-line;min-height:64px;
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;}
.preview-title{font-family:'Inter',sans-serif;font-size:14px;font-weight:800;color:var(--ink);margin-bottom:8px;white-space:normal;}
.preview-none{color:var(--muted);font-style:italic;font-family:'Inter',sans-serif;}

.up-submit{display:flex;gap:9px;margin-top:16px;flex-wrap:wrap;}
</style>

<div class="up-card" id="update">
    <div class="up-head">
        <div class="up-head-icon"><i class="fas fa-file-signature"></i></div>
        <div>
            <h2>Permit &amp; Payment Update</h2>
            <p>Message one vendor privately — the notice is written from their own record</p>
        </div>
    </div>

    <div class="up-body">
        <form method="post" action="admin_notifications.php" id="updateForm">
            <input type="hidden" name="action" value="permit_update" />

            <div class="up-grid">

                <!-- LEFT: who + what -->
                <div>
                    <div class="field">
                        <label>Vendor <span class="req">*</span></label>
                        <select name="vendor_id" id="upVendor" required>
                            <option value="">— Select a vendor —</option>
                            <?php foreach ($vendors as $v):
                                $vName = trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? ''));
                            ?>
                                <option value="<?= (int)$v['id'] ?>">
                                    <?= h($vName) ?> — Stall <?= h($v['stall_number']) ?> (<?= h($v['market_section']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Current record, filled in from the database -->
                    <div class="rec" id="upRecord">
                        <div class="rec-empty" id="upRecEmpty">
                            <i class="fas fa-circle-info"></i> Pick a vendor to see their current permit and payment status.
                        </div>
                        <div id="upRecBody" style="display:none;">
                            <div class="rec-top">
                                <div class="rec-avatar" id="upRecAvatar">--</div>
                                <div>
                                    <div class="rec-name" id="upRecName">—</div>
                                    <div class="rec-sub" id="upRecSub">—</div>
                                </div>
                            </div>
                            <div class="rec-rows">
                                <div class="rec-row"><div class="k">Permit</div><div class="v" id="upRecPermit">—</div></div>
                                <div class="rec-row"><div class="k">Valid until</div><div class="v" id="upRecExpiry">—</div></div>
                                <div class="rec-row"><div class="k">Payment</div><div class="v" id="upRecPay">—</div></div>
                                <div class="rec-row"><div class="k">Next due</div><div class="v" id="upRecDue">—</div></div>
                            </div>
                        </div>
                    </div>

                    <div class="field">
                        <label>What changed? <span class="req">*</span></label>
                        <select name="update_action" id="upAction" required>
                            <optgroup label="Permit">
                                <?php foreach ($UPDATE_ACTIONS as $key => $meta):
                                    if ($meta['group'] !== 'Permit') continue; ?>
                                    <option value="<?= h($key) ?>"><?= h($meta['label']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Payment">
                                <?php foreach ($UPDATE_ACTIONS as $key => $meta):
                                    if ($meta['group'] !== 'Payment') continue; ?>
                                    <option value="<?= h($key) ?>"><?= h($meta['label']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <span class="hint">Pick the change first — the form shows only what it needs.</span>
                    </div>

                    <label class="check-row">
                        <input type="checkbox" name="apply_record" value="1" checked />
                        <span>
                            <span class="ct"><i class="fas fa-floppy-disk"></i> Also save to the vendor's record</span>
                            <span class="cd">Uncheck to send the notice only and leave the permit/payment status as it is.</span>
                        </span>
                    </label>
                </div>

                <!-- RIGHT: the details + preview -->
                <div>
                    <div class="up-fields">
                        <div class="field" data-for="expiry" hidden>
                            <label><?= h($FIELD_LABELS['expiry']) ?></label>
                            <input type="date" name="expiry" id="upExpiry" />
                        </div>
                        <div class="field" data-for="months" hidden>
                            <label><?= h($FIELD_LABELS['months']) ?></label>
                            <input type="number" name="months" id="upMonths" min="0" max="60" step="1" placeholder="12" />
                        </div>
                        <div class="field" data-for="amount" hidden>
                            <label><?= h($FIELD_LABELS['amount']) ?></label>
                            <input type="number" name="amount" id="upAmount" min="0" step="0.01" placeholder="0.00" />
                        </div>
                        <div class="field" data-for="or_number" hidden>
                            <label><?= h($FIELD_LABELS['or_number']) ?></label>
                            <input type="text" name="or_number" id="upOrNo" maxlength="60" placeholder="e.g. 00012345" />
                        </div>
                        <div class="field" data-for="period" hidden>
                            <label><?= h($FIELD_LABELS['period']) ?></label>
                            <input type="text" name="period" id="upPeriod" maxlength="60" placeholder="e.g. November 2026" />
                        </div>
                        <div class="field" data-for="next_due" hidden>
                            <label><?= h($FIELD_LABELS['next_due']) ?></label>
                            <input type="date" name="next_due" id="upNextDue" />
                        </div>
                        <div class="field" data-for="notes" hidden style="grid-column:1/-1;">
                            <label><?= h($FIELD_LABELS['notes']) ?></label>
                            <input type="text" name="notes" id="upNotes" maxlength="500" placeholder="Anything else the vendor should know…" />
                        </div>
                    </div>

                    <div class="preview">
                        <div class="preview-head">
                            <i class="fas fa-eye"></i> What the vendor will read
                        </div>
                        <div class="preview-body" id="upPreview">
                            <span class="preview-none">Choose a vendor and an update to preview the message.</span>
                        </div>
                    </div>

                    <!-- SMS opt-in + what their phone will show. The inbox copy above
                         is always created; this only adds the text on top. -->
                    <?php if (!empty($SMS_READY)): ?>
                    <label class="check-row" style="margin-top:16px;">
                        <input type="checkbox" name="send_sms" value="1" checked />
                        <span>
                            <span class="ct"><i class="fas fa-comment-sms"></i> Also text this vendor</span>
                            <span class="cd">The notice above goes to their phone as well. Skipped when their number is not a valid mobile line.</span>
                        </span>
                    </label>

                    <div class="sms-phone">
                        <div class="sms-screen"><div class="sms-bubble" id="upSmsPreview"></div></div>
                        <span class="hint" id="upSmsCounter">Trimmed to <?= (int)$SMS_CFG['max_length'] ?> characters.</span>
                    </div>
                    <?php endif; ?>

                    <div class="up-submit">
                        <button type="submit" class="btn-form primary" style="flex:1;min-width:200px;">
                            <i class="fas fa-paper-plane"></i> Send Private Message
                        </button>
                    </div>
                </div>

            </div>
        </form>
    </div>
</div>

<script>
/* Which inputs each action needs (mirrors $ACTION_FIELDS above). */
const UP_FIELDS = <?= json_encode($ACTION_FIELDS, JSON_UNESCAPED_SLASHES) ?>;
const UP_DATA   = <?= json_encode($vendorJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

const upVendor   = document.getElementById('upVendor');
const upAction   = document.getElementById('upAction');
const upFields   = {};
document.querySelectorAll('.up-fields [data-for]').forEach(w => upFields[w.dataset.for] = w);

const upGet = id => document.getElementById(id).value.trim();

/* ---------- small formatting helpers ---------- */
function upMoney(n) {
    const v = parseFloat(n);
    return '₱' + (isNaN(v) ? '0.00' : v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
}
function upDate(iso, fallback) {
    if (!iso) return fallback || '—';
    const d = new Date(iso + 'T00:00:00');
    if (isNaN(d)) return fallback || '—';
    return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}
function upInMonths() {
    const d = new Date();
    d.setMonth(d.getMonth() + 1);
    return d.toISOString().slice(0, 10);
}

/* ---------- the vendor's current record ---------- */
function upVendorRecord() {
    return UP_DATA[upVendor.value] || null;
}

function renderRecord() {
    const v = upVendorRecord();
    const empty = document.getElementById('upRecEmpty');
    const body  = document.getElementById('upRecBody');

    if (!v) {
        empty.style.display = '';
        body.style.display = 'none';
        return;
    }
    empty.style.display = 'none';
    body.style.display = '';

    const initials = ((v.first || 'U')[0] + (v.name.split(' ')[1] || '')[0]).toUpperCase();
    document.getElementById('upRecAvatar').textContent = initials;
    document.getElementById('upRecName').textContent   = v.name + (v.business ? ' — ' + v.business : '');
    document.getElementById('upRecSub').textContent    = 'Stall ' + v.stall + ' · ' + v.section;
    document.getElementById('upRecPermit').textContent = v.permit;
    document.getElementById('upRecExpiry').textContent = upDate(v.expiry);
    document.getElementById('upRecPay').textContent    = v.payment;
    document.getElementById('upRecDue').textContent    = upDate(v.due);
}

/* ---------- show only the fields this action needs ---------- */
function renderFields() {
    const need = UP_FIELDS[upAction.value] || [];
    Object.keys(upFields).forEach(k => { upFields[k].hidden = !need.includes(k); });

    /* Pre-fill from the record so the admin confirms rather than types. */
    const v = upVendorRecord();
    if (v) {
        if (need.includes('expiry') && !upGet('upExpiry')) {
            const base = (v.expiry && new Date(v.expiry + 'T00:00:00') > new Date()) ? v.expiry : '';
            document.getElementById('upExpiry').value = base;
        }
        if (need.includes('next_due') && !upGet('upNextDue')) {
            document.getElementById('upNextDue').value = (upAction.value === 'payment_paid') ? upInMonths() : (v.due || '');
        }
    }
}

/* ---------- live preview ----------
   Mirrors permit_update_message() in config/notify.php. The server
   rebuilds the same text on submit; this is only for the admin's
   eyes before they send. */
function buildMessage() {
    const v = upVendorRecord();
    if (!v) return null;

    const greet = v.first ? 'Hi ' + v.first + ', ' : 'Hi, ';
    const stall = v.stall || '—';
    const notes = upGet('upNotes');
    const amt   = parseFloat(upGet('upAmount')) || 0;
    const orNo  = upGet('upOrNo');
    const period= upGet('upPeriod');
    const months= parseInt(upGet('upMonths'), 10) || 0;
    const extra = notes ? '\n\nNote from the market office: ' + notes : '';
    const dot   = (s) => '\n• ' + s;

    /* Money always changes hands at the counter (PAYMENT_METHOD), so
       this line is constant — same as permit_update_message(). */
    const settled = dot('Settled at: Market Office (<?= PAYMENT_METHOD ?>)');

    let title = '', body = '';
    switch (upAction.value) {
        case 'permit_valid':
            title = 'Your business permit is now valid';
            body  = greet + 'your business permit is active.' + dot('Stall: ' + stall)
                  + dot('Valid until: ' + upDate(upGet('upExpiry') || v.expiry))
                  + (months > 0 ? dot('Term: ' + months + ' month' + (months === 1 ? '' : 's')) : '')
                  + (amt > 0 ? dot('Fee paid: ' + upMoney(amt)) : '')
                  + (amt > 0 ? settled : '')
                  + (orNo ? dot('OR Number: ' + orNo) : '')
                  + '\n\nPlease display your permit at your stall at all times.' + extra;
            break;

        case 'permit_expired':
            title = 'Your business permit has expired';
            body  = greet + 'your business permit is no longer valid.' + dot('Stall: ' + stall)
                  + dot('Expired on: ' + upDate(upGet('upExpiry') || v.expiry))
                  + '\n\nPlease renew at the market office to avoid any interruption to your business.' + extra;
            break;

        case 'permit_suspended':
            title = 'Your business permit has been suspended';
            body  = greet + 'your business permit is now suspended and you cannot operate at your stall until it is reinstated.'
                  + dot('Stall: ' + stall)
                  + '\n\nPlease visit the market office to settle the outstanding issue.' + extra;
            break;

        case 'permit_pending':
            title = 'Your business permit is pending review';
            body  = greet + 'your business permit is back under review. We need to verify a few details before it becomes valid again.'
                  + dot('Stall: ' + stall)
                  + '\n\nWe will notify you as soon as the review is complete.' + extra;
            break;

        case 'payment_paid':
            title = 'Payment received' + (amt > 0 ? ' — ' + upMoney(amt) : '');
            body  = greet + 'we have received your stall rental payment' + (amt > 0 ? ' of ' + upMoney(amt) : '') + '.'
                  + dot('Amount: ' + upMoney(amt))
                  + settled
                  + (orNo ? dot('OR Number: ' + orNo) : '')
                  + (period ? dot('Period covered: ' + period) : '')
                  + dot('Next due: ' + upDate(upGet('upNextDue') || upInMonths()))
                  + '\n\nThank you! Your account is now marked as Paid.' + extra;
            break;

        case 'payment_partial':
            title = 'Partial payment recorded' + (amt > 0 ? ' — ' + upMoney(amt) : '');
            body  = greet + 'we have recorded a partial payment of ' + upMoney(amt) + '.'
                  + dot('Amount received: ' + upMoney(amt))
                  + settled
                  + (orNo ? dot('OR Number: ' + orNo) : '')
                  + (period ? dot('Period covered: ' + period) : '')
                  + dot('Balance still due: ' + upDate(v.due))
                  + '\n\nPlease settle the remaining balance to keep your account in good standing.' + extra;
            break;

        case 'payment_unpaid':
            title = 'Action needed — payment is due';
            body  = greet + 'our records show no payment received for your stall.'
                  + dot('Stall: ' + stall)
                  + dot('Amount due: ' + upMoney(amt))
                  + dot('Due date: ' + upDate(upGet('upNextDue') || v.due))
                  + '\n\nPlease settle your account at the market office to avoid penalties.' + extra;
            break;

        default:
            title = 'Update from the market office';
            body  = greet + 'there is an update on your record. Please check your dashboard.';
    }
    return { title, body };
}

function renderPreview() {
    const el = document.getElementById('upPreview');
    const msg = buildMessage();
    if (!msg) {
        el.innerHTML = '<span class="preview-none">Choose a vendor and an update to preview the message.</span>';
        return;
    }
    el.innerHTML = '';
    const t = document.createElement('div');
    t.className = 'preview-title';
    t.textContent = msg.title;
    el.appendChild(t);
    el.appendChild(document.createTextNode(msg.body));
}

/* Any detail that feeds the message feeds the text copy too. */
function refreshText() {
    renderPreview();
    renderUpSmsPreview();
}

function refreshUpdatePanel() {
    renderRecord();
    renderFields();
    renderPreview();
    renderUpSmsPreview();
}

/* ---------- text-message preview ----------
   Mirrors config/sms.php sms_format_body() on top of the message
   above, so the admin sees the real phone copy — prefix included,
   clamped to SMS_MAX_LENGTH. */
const UP_SMS_PREFIX = <?= json_encode($SMS_CFG['sender_prefix']) ?>;
const UP_SMS_MAX    = <?= (int)$SMS_CFG['max_length'] ?>;

/* Trim on a whole character boundary, ellipsis and all, exactly
   like sms_clamp() does. */
function upSmsClamp(text, max) {
    if (max < 1) max = 1;
    return Array.from(text).length <= max
        ? text
        : Array.from(text).slice(0, max - 1).join('').replace(/\s+$/, '') + '…';
}

function renderUpSmsPreview() {
    const bubble = document.getElementById('upSmsPreview');
    if (!bubble) return;

    const msg = buildMessage();
    let text = msg ? msg.title + '\n' + msg.body.replace(/[ \t]+/g, ' ').trim().replace(/\n{3,}/g, '\n\n') : '';
    if (msg && UP_SMS_PREFIX) text = UP_SMS_PREFIX + ': ' + text;

    const shown = upSmsClamp(text, UP_SMS_MAX);
    const chars = Array.from(shown).length;
    const cut   = chars === UP_SMS_MAX && chars < Array.from(text).length;

    bubble.textContent = shown || 'Pick a vendor and an update to see the text they get.';
    bubble.classList.toggle('empty', shown === '');

    const counter = document.getElementById('upSmsCounter');
    if (counter) {
        counter.textContent = shown === ''
            ? 'Nothing to send yet.'
            : chars + ' / ' + UP_SMS_MAX + ' character' + (UP_SMS_MAX === 1 ? '' : 's')
              + (cut ? ' — trimmed, the rest is cut off.' : '');
    }
}

upVendor.addEventListener('change', refreshUpdatePanel);
upAction.addEventListener('change', function () { renderFields(); renderPreview(); renderUpSmsPreview(); });
['upExpiry', 'upMonths', 'upAmount', 'upOrNo', 'upPeriod', 'upNextDue', 'upNotes']
    .forEach(id => {
        const el = document.getElementById(id);
        el.addEventListener('input', refreshText);
        el.addEventListener('change', refreshText);
    });

/* Deep link from the "Needs Attention" list: ?vendor=<id>&focus=<action> */
const upParams = new URLSearchParams(window.location.search);
const upPreset = upParams.get('vendor');
if (upPreset && UP_DATA[upPreset]) {
    upVendor.value = upPreset;
    const focus = upParams.get('focus');
    if (focus && UP_FIELDS[focus]) upAction.value = focus;
    refreshUpdatePanel();
    document.getElementById('update').scrollIntoView({ behavior: 'smooth', block: 'start' });
} else {
    refreshUpdatePanel();
}

/* A payment notice without a figure reads badly — catch it here. */
document.getElementById('updateForm').addEventListener('submit', function (e) {
    const action = upAction.value;
    const amt = parseFloat(upGet('upAmount')) || 0;
    if ((action === 'payment_partial' || action === 'payment_unpaid') && amt <= 0) {
        e.preventDefault();
        alert('Enter the amount before sending that payment notice.');
        document.getElementById('upAmount').focus();
        return;
    }
    const v = upVendorRecord();
    const name = v ? v.name + ' (Stall ' + v.stall + ')' : 'this vendor';
    const smsBox = document.querySelector('input[name="send_sms"]');
    const verb = document.querySelector('input[name="apply_record"]').checked
        ? 'update their record and send the notice'
        : 'send the notice (record left unchanged)';
    const tail = (smsBox && smsBox.checked) ? ' and text it to their phone' : '';
    if (!confirm('About to ' + verb + tail + ' for ' + name + '. Continue?')) e.preventDefault();
});
</script>