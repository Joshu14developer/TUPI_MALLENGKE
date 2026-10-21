<?php
/* ============================================================
   BOARD SEQUENCE CONTROLS  (shared)
   ------------------------------------------------------------
   The bulletin board and the private-message history are both long
   lists. Instead of letting them stretch the page, each sits in a
   fixed-height panel that scrolls inside itself, and a small
   sequence control steps through the items one at a time.

   Contract — the page only has to provide the markup:

     <div class="card">
       <div class="card-head">
         …
         <div class="seq" data-seq>
           <button type="button" class="seq-btn" data-seq-prev>…</button>
           <span class="seq-count" data-seq-count>1 of 1</span>
           <button type="button" class="seq-btn" data-seq-next>…</button>
         </div>
       </div>
       <div class="board" data-board> … items … </div>
     </div>

   Include this anywhere inside <body>, after that markup. Every
   [data-seq] control picks up the [data-board] in its own card.
   ============================================================ */
?>
<style>
/* A board that scrolls inside itself rather than stretching the page */
.board{max-height:min(520px,58vh);overflow-y:auto;overscroll-behavior:contain;scroll-behavior:smooth;scrollbar-width:thin;scrollbar-color:var(--green-200) var(--bg);}
.board:focus-visible{outline:2px solid var(--green-400);outline-offset:-2px;}
.board::-webkit-scrollbar{width:9px;}
.board::-webkit-scrollbar-track{background:var(--bg);}
.board::-webkit-scrollbar-thumb{background:var(--green-200);border-radius:5px;border:2px solid #fff;}
.board::-webkit-scrollbar-thumb:hover{background:var(--green-300);}

/* Wider boards get a little more of the page. */
@media(min-width:1100px){.board.wide{max-height:min(620px,66vh);}}

/* Sequence control: step through the items */
.seq{margin-left:auto;display:flex;align-items:center;gap:6px;flex-shrink:0;}
.seq-btn{width:30px;height:30px;border-radius:9px;border:1.5px solid var(--line);background:#fff;color:var(--ink-soft);cursor:pointer;display:grid;place-items:center;font-size:11px;transition:all .2s var(--ease-spring);font-family:inherit;}
.seq-btn:hover:not(:disabled){border-color:var(--green-400);background:var(--green-50);color:var(--brand-dark);transform:translateY(-2px);}
.seq-btn:active:not(:disabled){transform:translateY(0);}
.seq-btn:disabled{opacity:.38;cursor:not-allowed;}
.seq-count{font-size:11px;font-weight:800;color:var(--muted);min-width:54px;text-align:center;font-variant-numeric:tabular-nums;letter-spacing:.02em;}

/* Nothing to step through — keep the header tidy. */
.seq[hidden]{display:none;}
</style>

<script>
/* ============================================================
   Step through a board
   ------------------------------------------------------------
   The panel scrolls, and these buttons jump a whole item at a time.
   Item heights differ, so the target is worked out from the real
   offsets rather than assuming a row size.
   ============================================================ */
(function () {
    const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.querySelectorAll('[data-seq]').forEach(function (seq) {
        const card  = seq.closest('.card');
        const board = (card ? card.querySelector('[data-board]') : null)
                   || document.querySelector('[data-board]');
        if (!board) return;

        const prev  = seq.querySelector('[data-seq-prev]');
        const next  = seq.querySelector('[data-seq-next]');
        const label = seq.querySelector('[data-seq-count]');
        const items = Array.from(board.children).filter(el => el.nodeType === 1);

        /* One item, or none: a stepper would be noise. */
        if (items.length < 2) { seq.hidden = true; return; }

        /* Positions are read relative to the first item, because
           offsetTop depends on whatever ancestor happens to be
           positioned — the siblings' own layout is what matters. */
        let tops = [];
        function measure() {
            const base = items[0].offsetTop;
            tops = items.map(el => el.offsetTop - base);
        }
        measure();

        const maxScroll = () => Math.max(0, board.scrollHeight - board.clientHeight);
        const atTop    = () => board.scrollTop <= 2;
        const atBottom = () => board.scrollTop >= maxScroll() - 2;

        /* The item the admin is looking at: the last one whose top has
           passed the fold. */
        function index() {
            let i = 0;
            for (let k = 0; k < tops.length; k++) {
                if (tops[k] <= board.scrollTop + 8) i = k; else break;
            }
            return i;
        }

        function sync() {
            /* Sitting at the very bottom means the admin has reached the
               last notice, so say so even when its top is above the fold. */
            const i = atBottom() ? items.length - 1 : index();
            if (label) label.textContent = (i + 1) + ' of ' + items.length;
            if (prev) prev.disabled = atTop();
            if (next) next.disabled = atBottom();
        }

        function goTo(k) {
            board.scrollTo({
                top: Math.min(Math.max(0, tops[k]), maxScroll()),
                behavior: calm ? 'auto' : 'smooth'
            });
            /* Let the smooth scroll land before the buttons re-read. */
            setTimeout(sync, calm ? 0 : 260);
        }

        if (prev) prev.addEventListener('click', function () {
            const i = index();
            goTo(i > 0 ? i - 1 : 0);
        });

        if (next) next.addEventListener('click', function () {
            const fold = board.scrollTop + board.clientHeight;
            /* The first item not fully visible yet; falls back to the
               last one so the final click always lands at the end. */
            let target = items.length - 1;
            for (let k = 0; k < items.length; k++) {
                if (tops[k] + items[k].offsetHeight > fold + 2) { target = k; break; }
            }
            goTo(target);
        });

        /* Scrolling by wheel, drag or keyboard keeps the counter true. */
        board.addEventListener('scroll', function () {
            window.clearTimeout(board.dataset.seqTimer);
            board.dataset.seqTimer = window.setTimeout(sync, 90);
        }, { passive: true });

        window.addEventListener('resize', function () { measure(); sync(); });
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () { measure(); sync(); });
        }
        sync();
    });
})();
</script>