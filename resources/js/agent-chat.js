// The chat page's client side. A question is handed to the server the moment it is sent (an
// optimistic bubble until the transcript shows it); the answer is produced by a queued job and
// this component listens to the turn's event stream for it — every change pushed as it happens —
// and polls the turn endpoint when the stream cannot be held open: fast while a turn runs, slowly
// while the page is open, so a reload, a second tab or a chat reopened mid-answer all show the same
// thing. Questions typed while an answer runs wait on the server, per conversation. ↑ in an empty
// composer pulls the last waiting question back for editing — or the last one sent. "@" offers the
// panel's records, "/" the starter questions; files drop, paste or attach; a draft survives a reload.
export default function agentChat() {
    // The context of the component's root element, taken in init(). Alpine evaluates a click handler with `this`
    // bound to the clicked element, and its $wire/$refs/$root follow that element: once a morph has removed it
    // (Retry, Regenerate, Approve, a queued question's Edit), $wire becomes a silent no-op and $root is null. So
    // nothing that runs later — the poll timer, the pump, a continuation after an await — may keep a click's
    // `this`; it goes through `self`, whose element lives as long as the page.
    let self = null

    // Motion, kept off Alpine's reactivity (it runs every frame). The streamed answer is revealed at a steady pace
    // rather than in the chunks the server sends, and the page glides after the transcript as it grows instead of
    // jumping; both are instant under prefers-reduced-motion.
    const calm = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches
    const reveal = { source: null, total: 0, shown: 0, speed: 0, frame: 0, last: 0, waiters: [] }
    const glide = { frame: 0, last: 0 }
    let pinned = false
    // The transcript's tallest height and the scroll position that showed it (see hold()).
    const held = { height: 0, y: 0 }
    let handoff = null
    // The text being typed in, per element (a WeakMap: a Livewire morph strips attributes it did not render).
    const typing = new WeakMap()

    // A copy of rendered HTML cut after `length` characters of text, its open tags kept.
    const cut = (source, length) => {
        const copy = source.cloneNode(true)
        let left = Math.max(0, Math.floor(length))
        const walker = document.createTreeWalker(copy, NodeFilter.SHOW_TEXT)
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            if (node.data.length < left) {
                left -= node.data.length
                continue
            }
            const range = document.createRange()
            range.setStart(node, left)
            range.setEndAfter(copy.lastChild)
            range.deleteContents()
            break
        }
        return copy
    }

    return {
        outbox: [],
        sending: null,
        history: [],
        atBottom: true,
        sequence: 0,
        pumping: false,
        pollUrl: null,
        streamUrl: null,
        turn: null,
        interval: 600,
        live: { status: '', html: '', tools: [] },
        handing: false,
        version: null,
        editing: false,
        stopping: false,
        timer: null,
        clearOnSync: false,
        source: null,
        streamFailures: 0,
        dragging: false,
        mentions: [],
        picker: { open: false, kind: null, query: '', items: [], index: 0, start: 0, loading: false, timer: null },
        draftTimer: null,
        titleSuffix: '',

        get busy() {
            return this.turn !== null || this.sending !== null || this.outbox.length > 0
        },

        init() {
            self = this

            // Per-render values come from the state element (see the page): x-data itself stays constant.
            const state = this.$refs.state.dataset
            this.pollUrl = state.poll || null
            this.streamUrl = state.stream || null
            this.turn = state.active || null
            this.interval = parseInt(state.interval) || 600
            this.titleSuffix = document.title.startsWith(state.title) ? document.title.slice(state.title.length) : ''

            // Keep the end of the transcript in view while it grows, unless the person scrolled up to read; and give
            // every code block its copy button and colours as it appears.
            // A part that changes size without a DOM change (an image loading, a card's transition) is caught by the
            // ResizeObserver; both run before the frame is painted, so a held height never shows as a jump.
            const sizes = new ResizeObserver(() => this.hold())
            const watch = () => { for (const part of this.$refs.transcript.children) sizes.observe(part) }
            new MutationObserver((mutations) => { watch(); this.enter(mutations); this.decorate(); this.hold(); this.pinComposer(); this.follow() }).observe(this.$refs.transcript, { childList: true, subtree: true, characterData: true })
            watch()
            // A chat opened from another one (wire:navigate) starts without the last one's floor.
            document.documentElement.style.removeProperty('--fi-chat-floor')
            window.addEventListener('scroll', () => { held.y = window.scrollY; if (! glide.frame) this.atBottom = this.nearBottom() }, { passive: true })
            // Scrolling up to read stops the glide at once (its own scroll events cannot tell a person's apart).
            const away = () => { self.atBottom = false; cancelAnimationFrame(glide.frame); glide.frame = 0 }
            window.addEventListener('wheel', (event) => { if (event.deltaY < 0) away() }, { passive: true })
            window.addEventListener('touchmove', away, { passive: true })
            window.addEventListener('keydown', (event) => {
                if (['PageUp', 'ArrowUp', 'Home'].includes(event.key) && ! event.target.closest?.('input, textarea, [contenteditable]')) away()
            })
            this.$watch('live.html', (html) => self.stream(html))
            document.addEventListener('visibilitychange', () => { if (! document.hidden) this.schedule(0) })
            window.addEventListener('pagehide', () => this.closeStream())

            // The server-rendered transcript takes over from the optimistic bubble (and from the streamed answer once
            // it is stored) in the same DOM update as the morph; the tab title follows the chat's.
            const root = this.$root.closest('[wire\\:id]')
            Livewire.interceptMessage(({ message, onSuccess }) => {
                if (message.component.el !== root) return
                onSuccess(({ onSync }) => onSync(() => {
                    this.sending = null
                    if (this.clearOnSync) {
                        this.clearOnSync = false
                        this.handing = false
                        this.live = { status: '', html: '', tools: [] }
                    }
                    this.syncTitle()
                }))
            })

            this.pinComposer()
            window.addEventListener('resize', () => {
                pinned = false
                held.height = 0
                document.documentElement.style.removeProperty('--fi-chat-floor')
                self.pinComposer()
            }, { passive: true })

            this.decorate()
            this.autosize()
            if (state.autoSend && state.prompt.trim() !== '') {
                this.enqueue(state.prompt.trim())
            } else {
                this.restoreDraft()
            }
            this.schedule(0)
        },

        submit() {
            const text = this.$refs.input.value.trim()
            const files = this.$refs.files ? this.$refs.files.files.length : 0
            if (text === '' && files === 0) return
            this.closePicker()
            this.$refs.input.value = ''
            this.autosize()
            this.clearDraft()
            this.editing ? this.resend(text) : this.enqueue(text)
        },

        enqueue(text) {
            this.outbox.push({ id: ++this.sequence, text, mentions: this.takeMentions(text) })
            this.$nextTick(() => this.scrollToBottom(true))
            this.pump()
        },

        // One send at a time: the server queues what arrives while a turn runs. The next send waits for a tick —
        // an action fired inside the previous call's continuation, before Livewire has finished that message, is dropped.
        async pump() {
            if (this.pumping || this.outbox.length === 0) return
            this.pumping = true
            const next = this.outbox.shift()
            this.sending = next
            this.history.push(next.text)
            try {
                self.started(await self.$wire.send(next.text, next.mentions))
            } finally {
                self.sending = null
                self.pumping = false
                setTimeout(() => self.pump(), 0)
            }
        },

        // The server told us a turn was queued: listen to it (a new chat also tells us where).
        started(result) {
            if (! result) return
            if (result.poll) this.pollUrl = result.poll
            if (result.stream) this.streamUrl = result.stream
            if (result.active && this.turn === null) {
                this.turn = result.turn
                this.live = { status: '', html: '', tools: [] }
            }
            this.schedule(0)
        },

        async resend(text) {
            this.editing = false
            this.history.push(text)
            this.started(await this.$wire.resend(text))
        },

        async regenerate() {
            this.started(await this.$wire.regenerate())
        },

        async continueAnswer() {
            this.started(await this.$wire.continueAnswer())
        },

        // Retry under an unanswered question: the last one, or an earlier one by its id (the server moves it to the end).
        async retry(id = null) {
            this.started(await this.$wire.retry(id))
        },

        async decide(id, approve) {
            this.started(await this.$wire.decide(id, approve))
            self.$refs.input?.focus()
        },

        async stop() {
            if (this.turn === null || this.stopping) return
            this.stopping = true
            await this.$wire.stop()
        },

        // Edit the last question and send it again: the composer takes its text, the answer is replaced.
        editLast(text) {
            this.editing = true
            this.draft(text)
        },

        cancelEdit() {
            this.editing = false
            this.$refs.input.value = ''
            this.autosize()
        },

        // A question waiting on the server: edit it (its text comes back to the composer) or take it out of the line.
        async editQueued(id) {
            const typed = this.$refs.input.value.trim()
            const text = await this.$wire.editQueued(id)
            if (! text) return
            if (typed !== '') setTimeout(() => self.enqueue(typed), 0)
            self.draft(text)
        },

        async removeQueued(id) {
            await this.$wire.removeQueued(id)
        },

        // ↑ in an empty composer: edit the last question still waiting (here, then on the server), else recall the last one sent.
        recall(event) {
            if (this.$refs.input.value !== '') return
            const queued = this.outbox.pop()
            if (queued) {
                event.preventDefault()
                this.draft(queued.text)
                return
            }
            const waiting = this.queuedIds()
            if (waiting.length > 0) {
                event.preventDefault()
                this.editQueued(waiting.at(-1))
                return
            }
            const text = this.history.at(-1)
            if (! text) return
            event.preventDefault()
            this.draft(text)
        },

        queuedIds() {
            try {
                return JSON.parse(this.$refs.queued?.dataset.turns ?? '[]')
            } catch {
                return []
            }
        },

        // Edit a question still in the outbox; whatever was being typed takes its place so nothing is lost.
        edit(id) {
            const index = this.outbox.findIndex((m) => m.id === id)
            if (index === -1) return
            const typed = this.$refs.input.value.trim()
            const [queued] = this.outbox.splice(index, 1, ...(typed === '' ? [] : [{ id: ++this.sequence, text: typed, mentions: this.takeMentions(typed) }]))
            this.draft(queued.text)
        },

        remove(id) {
            this.outbox = this.outbox.filter((m) => m.id !== id)
        },

        // Live updates. While a turn runs the event stream pushes every change; when it cannot be held open (a proxy
        // that buffers, a browser without EventSource) the poll takes over at the poll interval. Idle, a slow poll
        // keeps a second tab in step.
        schedule(delay) {
            clearTimeout(self.timer)
            if (! self.pollUrl) return
            if (self.turn !== null && self.canStream()) {
                self.listen()
                return
            }
            self.timer = setTimeout(() => self.poll(), delay)
        },

        canStream() {
            return this.streamUrl !== null && typeof EventSource !== 'undefined' && this.streamFailures < 2
        },

        listen() {
            if (this.source) return
            let source
            try {
                source = new EventSource(this.streamUrl + (this.version ? '?version=' + encodeURIComponent(this.version) : ''), { withCredentials: true })
            } catch {
                this.streamFailures = 2
                this.schedule(0)
                return
            }
            this.source = source
            source.addEventListener('turn', (event) => {
                self.streamFailures = 0
                try { self.apply(JSON.parse(event.data)) } catch { /* a malformed event; the next one will do */ }
            })
            source.addEventListener('end', () => {
                self.closeStream()
                self.schedule(0)
            })
            source.onerror = () => {
                // The browser reconnects on its own after the server's close; an error before any event came counts as a failure.
                if (source.readyState === EventSource.CLOSED) {
                    self.streamFailures++
                    self.closeStream()
                    self.timer = setTimeout(() => self.poll(), self.interval)
                }
            }
        },

        closeStream() {
            if (this.source) {
                this.source.close()
                this.source = null
            }
        },

        // The turn endpoint: the answer so far while a turn runs, and a version stamp that changes when the
        // conversation did (another tab, the job finishing) so the transcript is re-rendered from the store.
        async poll() {
            if (! this.pollUrl) return
            if (document.hidden) return this.schedule(2000)
            try {
                const response = await fetch(this.pollUrl, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' })
                if (response.status === 401 || response.status === 403 || response.status === 404) { this.pollUrl = null; return }
                if (response.ok) this.apply(await response.json())
            } catch {
                // A network hiccup: the next poll will catch up.
            }
            if (this.turn !== null && this.canStream()) return this.listen()
            this.schedule(this.turn !== null ? this.interval : 4000)
        },

        apply(data) {
            const hadTurn = this.turn !== null
            if (data.active) {
                this.turn = data.active.id
                this.live = { status: data.active.statusText ?? '', html: data.active.html ?? '', tools: data.active.tools ?? [] }
            } else {
                this.turn = null
                this.stopping = false
            }
            const changed = this.version !== null && this.version !== data.version
            this.version = data.version
            if (changed || (hadTurn && this.turn === null)) {
                // The stored transcript replaces the streamed answer in the same DOM update (onSync above), once the
                // reveal has caught up; until then the live answer, its tools and its status stay as they are, so
                // nothing collapses and comes back.
                this.clearOnSync = true
                if (hadTurn && this.turn === null) {
                    this.handing = true
                    handoff = { text: this.live.html !== '', tools: this.live.tools.length > 0 }
                }
                self.revealed().then(() => self.$wire.$refresh())
            }
        },

        // The tab says which chat this is, once the provider has titled it or the person renamed it.
        syncTitle() {
            const title = this.$refs.state?.dataset.title
            if (title && ! document.title.startsWith(title)) document.title = title + this.titleSuffix
        },

        draft(text) {
            const input = this.$refs.input
            input.value = text
            this.autosize()
            input.focus()
            input.setSelectionRange(text.length, text.length)
        },

        autosize() {
            const input = this.$refs.input
            input.style.height = 'auto'
            input.style.height = Math.min(input.scrollHeight, 192) + 'px'
        },

        // Drafts: what is typed survives a reload or a detour, per conversation, in this browser.
        draftKey() {
            return 'packstub-agents:draft:' + (this.$refs.state?.dataset.conversation || 'new')
        },

        restoreDraft() {
            try {
                const draft = localStorage.getItem(this.draftKey())
                if (draft && this.$refs.input.value === '') {
                    this.$refs.input.value = draft
                    this.autosize()
                }
            } catch { /* storage unavailable */ }
        },

        saveDraft() {
            try {
                const text = this.$refs.input.value
                text.trim() === '' ? localStorage.removeItem(this.draftKey()) : localStorage.setItem(this.draftKey(), text)
            } catch { /* storage unavailable */ }
        },

        clearDraft() {
            try { localStorage.removeItem(this.draftKey()) } catch { /* storage unavailable */ }
        },

        // Every keystroke: save the draft (debounced) and see whether a picker should open.
        typed() {
            clearTimeout(this.draftTimer)
            this.draftTimer = setTimeout(() => self.saveDraft(), 300)
            this.detectPicker()
        },

        // "@" anywhere offers records (the panel's agent resources), "/" at the start the starter questions.
        detectPicker() {
            const input = this.$refs.input
            const before = input.value.slice(0, input.selectionStart)
            const mention = this.$refs.state?.dataset.mentions ? before.match(/(?:^|\s)@([^\s@]*)$/) : null
            if (mention) {
                this.openPicker('mention', mention[1], before.length - mention[1].length - 1)
                return
            }
            const slash = before.match(/^\/([^\s]*)$/)
            if (slash && input.value === before) {
                this.openPicker('slash', slash[1], 0)
                return
            }
            if (this.picker.open) this.closePicker()
        },

        openPicker(kind, query, start) {
            const changed = ! this.picker.open || this.picker.kind !== kind || this.picker.query !== query
            this.picker.open = true
            this.picker.kind = kind
            this.picker.query = query
            this.picker.start = start
            if (! changed) return
            this.picker.index = 0
            if (kind === 'slash') {
                let suggestions = []
                try { suggestions = JSON.parse(this.$refs.state?.dataset.suggestions ?? '[]') } catch { suggestions = [] }
                this.picker.items = suggestions.filter((s) => s.toLowerCase().includes(query.toLowerCase())).map((s) => ({ label: s, detail: '' }))
                return
            }
            clearTimeout(this.picker.timer)
            this.picker.loading = true
            this.picker.timer = setTimeout(async () => {
                const results = await self.$wire.searchRecords(query)
                if (! self.picker.open || self.picker.query !== query) return
                self.picker.items = (results ?? []).map((r) => ({ label: r.label, detail: r.resource, ref: r.ref }))
                self.picker.index = 0
                self.picker.loading = false
            }, query === '' ? 0 : 200)
        },

        closePicker() {
            clearTimeout(this.picker.timer)
            this.picker.open = false
            this.picker.items = []
            this.picker.loading = false
        },

        // Put the picked item into the composer: a record as "@Its label" (remembered for the send), a starter question as the text.
        pick(index) {
            const item = this.picker.items[index]
            if (! item) return
            const input = this.$refs.input
            if (this.picker.kind === 'slash') {
                this.closePicker()
                this.draft(item.label)
                return
            }
            const after = input.value.slice(input.selectionStart)
            const text = input.value.slice(0, this.picker.start) + '@' + item.label + ' '
            this.mentions.push({ ref: item.ref, label: item.label })
            this.closePicker()
            input.value = text + after
            input.focus()
            input.setSelectionRange(text.length, text.length)
            this.autosize()
            this.saveDraft()
        },

        // The refs of the records still mentioned in the text that is sent.
        takeMentions(text) {
            const refs = this.mentions.filter((m) => text.includes('@' + m.label)).map((m) => m.ref)
            this.mentions = []
            return [...new Set(refs)]
        },

        // Files dropped on the composer or pasted into it join the picked ones (Livewire uploads them on change).
        dropFiles(event) {
            this.addFiles(event.dataTransfer?.files)
        },

        pasteFiles(event) {
            const files = [...(event.clipboardData?.files ?? [])]
            if (files.length === 0) return
            event.preventDefault()
            this.addFiles(files)
        },

        addFiles(files) {
            const input = this.$refs.files
            if (! input || ! files || files.length === 0) return
            const transfer = new DataTransfer()
            for (const file of input.files) transfer.items.add(file)
            for (const file of files) transfer.items.add(file)
            input.files = transfer.files
            input.dispatchEvent(new Event('change', { bubbles: true }))
        },

        // Copy an answer, or a code block, and say so on the button for a moment.
        async copy(button, text) {
            try {
                await navigator.clipboard.writeText(text)
            } catch {
                const area = document.createElement('textarea')
                area.value = text
                document.body.appendChild(area)
                area.select()
                document.execCommand('copy')
                area.remove()
            }
            const copied = this.$refs.state?.dataset.copied ?? 'Copied'
            const before = button.getAttribute('title')
            button.classList.add('fi-chat-copied')
            button.setAttribute('title', copied)
            setTimeout(() => { button.classList.remove('fi-chat-copied'); if (before) button.setAttribute('title', before) }, 1500)
        },

        // Code blocks in answers: a copy button and light colouring, once per block, as they render or stream in.
        decorate() {
            const copyLabel = this.$refs.state?.dataset.copy ?? 'Copy'
            for (const pre of this.$refs.transcript.querySelectorAll('.fi-chat-md pre:not([data-decorated])')) {
                pre.dataset.decorated = '1'
                const code = pre.querySelector('code') ?? pre
                const button = document.createElement('button')
                button.type = 'button'
                button.className = 'fi-chat-code-copy'
                button.title = copyLabel
                button.setAttribute('aria-label', copyLabel)
                button.textContent = copyLabel
                button.addEventListener('click', () => self.copy(button, code.textContent))
                pre.appendChild(button)
                this.highlight(code)
            }
        },

        highlight(code) {
            if (code.children.length > 0 || code.textContent.length > 20000) return
            const escape = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            const keywords = /\b(function|return|if|else|elseif|foreach|for|while|do|switch|case|break|continue|class|extends|implements|interface|trait|enum|new|use|namespace|public|protected|private|static|abstract|final|const|var|let|def|import|from|as|try|catch|finally|throw|throws|async|await|yield|null|true|false|self|this|select|from|where|join|left|inner|on|group|by|order|limit|insert|into|values|update|set|delete|and|or|not|in|is|match|fn|echo|print|require|include)\b/gi
            const html = escape(code.textContent).replace(
                /(\/\/[^\n]*|#(?!\[)[^\n]*|\/\*[\s\S]*?\*\/)|("(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*'|`(?:[^`\\]|\\.)*`)|(\b\d+(?:\.\d+)?\b)/g,
                (m, comment, string, number) => comment ? `<span class="hl-c">${m}</span>` : string ? `<span class="hl-s">${m}</span>` : `<span class="hl-n">${m}</span>`,
            ).replace(/(^|[^\w<"'-])(?![^<]*>)/g, '$1').replace(keywords, (m, _k, offset, whole) => {
                // Keywords outside spans only (a keyword inside a string or comment keeps its colour).
                const open = whole.lastIndexOf('<span', offset)
                const close = whole.lastIndexOf('</span>', offset)
                return open > close ? m : `<span class="hl-k">${m}</span>`
            })
            code.innerHTML = html
        },

        // The sticky composer stops as far above the window's edge as it sits above the end of the page (the panel's
        // own padding, which a theme may change). Otherwise it moves between the two whenever the glide runs a few
        // pixels behind the growing transcript and then catches up. A gap that is not padding (a footer under the
        // page) keeps the stylesheet's default. Measured once the page scrolls (a short page fills the window, so the
        // gap under it is not the padding), then again after a resize.
        pinComposer() {
            if (pinned || document.documentElement.scrollHeight <= window.innerHeight) return
            pinned = true
            const chat = self.$root
            if (! chat.querySelector(':scope > .fi-chat-bottom')) return
            const end = chat.getBoundingClientRect().bottom + window.scrollY - parseFloat(getComputedStyle(chat).paddingBottom)
            const gap = document.documentElement.scrollHeight - end
            // On <html>: a Livewire morph strips what it did not render from the chat's own element.
            const root = document.documentElement.style
            gap >= 8 && gap <= 64 ? root.setProperty('--fi-chat-stick', gap + 'px') : root.removeProperty('--fi-chat-stick')
        },

        // A part that gets shorter under a page following the answer (an approval card folding into its result, the
        // status line giving way to the text) would make the page shorter: the browser would scroll everything down,
        // and the next lines would push it back up. While the page follows, the transcript keeps the tallest height it
        // reached and the position that showed it; what comes next fills the space. Scrolled up to read, it lets go.
        hold() {
            // Its own height, measured with the floor lifted (the page is put back before anything is painted).
            const root = document.documentElement.style
            root.removeProperty('--fi-chat-floor')
            const height = self.$refs.transcript.offsetHeight
            if (height >= held.height || ! self.atBottom) {
                held.height = height
                return
            }
            root.setProperty('--fi-chat-floor', held.height + 'px')
            if (window.scrollY < held.y) window.scrollTo({ top: held.y, behavior: 'instant' })
        },

        nearBottom() {
            return window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 160
        },

        follow() {
            if (this.atBottom) this.glideToBottom()
        },

        scrollToBottom(smooth = false) {
            this.atBottom = true
            smooth ? this.glideToBottom() : window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' })
        },

        // Ease toward the end of the page, re-aimed every frame: content that keeps growing is followed, not jumped to.
        glideToBottom() {
            if (calm()) return window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' })
            if (glide.frame) return
            glide.last = performance.now()
            const step = (now) => {
                const dt = Math.min(0.05, Math.max(0, now - glide.last) / 1000)
                glide.last = now
                const target = document.documentElement.scrollHeight - window.innerHeight
                const distance = target - window.scrollY
                // Only ever forward: the page never scrolls back up after a part of the answer.
                if (! self.atBottom || distance < 1) {
                    glide.frame = 0
                    return
                }
                const move = distance * (1 - Math.exp(-dt / 0.1))
                window.scrollTo({ top: window.scrollY + Math.max(1, move), behavior: 'instant' })
                held.y = window.scrollY
                glide.frame = requestAnimationFrame(step)
            }
            glide.frame = requestAnimationFrame(step)
        },

        // The streamed answer, revealed at a steady pace: each new chunk sets a speed that shows it in about 0.6 s
        // (never slower than a quick reader), so text flows in rather than landing a paragraph at a time.
        stream(html) {
            const el = this.$refs.answer
            if (! el) return
            if (html === '') {
                Object.assign(reveal, { source: null, total: 0, shown: 0 })
                el.replaceChildren()
                return this.settle()
            }
            const template = document.createElement('template')
            template.innerHTML = html
            reveal.source = template.content
            reveal.total = template.content.textContent.length
            reveal.shown = Math.min(reveal.shown, reveal.total)
            if (calm()) reveal.shown = reveal.total
            reveal.speed = Math.max(240, (reveal.total - reveal.shown) / 0.6)
            this.paint()
            if (reveal.shown >= reveal.total) return this.settle()
            if (reveal.frame) return
            reveal.last = performance.now()
            const step = (now) => {
                reveal.shown = Math.min(reveal.total, reveal.shown + reveal.speed * Math.min(0.05, Math.max(0, now - reveal.last) / 1000))
                reveal.last = now
                self.paint()
                if (reveal.shown < reveal.total) {
                    reveal.frame = requestAnimationFrame(step)
                } else {
                    reveal.frame = 0
                    self.settle()
                }
            }
            reveal.frame = requestAnimationFrame(step)
        },

        // The answer up to the revealed character.
        paint() {
            const el = this.$refs.answer
            if (! reveal.source) return
            el.setAttribute('aria-busy', reveal.shown < reveal.total ? 'true' : 'false')
            el.replaceChildren(reveal.shown < reveal.total ? cut(reveal.source, reveal.shown) : reveal.source.cloneNode(true))
        },

        // A stored answer whose text was never on screen (a provider that does not stream, a fast turn, another tab)
        // writes itself out the same way; returns how long that takes, so the parts under it can wait their turn.
        typeIn(el) {
            const source = document.createDocumentFragment()
            source.append(...[...el.childNodes].map((node) => node.cloneNode(true)))
            const total = source.textContent.length
            const duration = Math.min(1500, Math.max(400, total * 2.5))
            const start = performance.now()
            el.setAttribute('aria-busy', 'true')
            // A morph that re-renders the text meanwhile is overwritten by the next frame; the last one always leaves
            // the whole text, so typing never ends short.
            const step = (now) => {
                if (! el.isConnected || typing.get(el) !== start) return
                const shown = total * Math.min(1, Math.max(0, now - start) / duration)
                if (shown >= total) {
                    el.replaceChildren(source)
                    el.removeAttribute('aria-busy')
                    typing.delete(el)
                    return
                }
                el.replaceChildren(cut(source, shown))
                requestAnimationFrame(step)
            }
            typing.set(el, start)
            el.replaceChildren()
            requestAnimationFrame(step)
            return duration
        },

        revealed() {
            return reveal.shown >= reveal.total ? Promise.resolve() : new Promise((resolve) => reveal.waiters.push(resolve))
        },

        settle() {
            reveal.waiters.splice(0).forEach((resolve) => resolve())
        },

        // New answers fade and rise into place, a part at a time, and so does a part added to an answer already there
        // (the rest of an answer resumed after an approval). At the handover from the live answer the parts that were
        // already on screen (the streamed text, the tools) are left alone: only what is new moves.
        enter(mutations) {
            if (calm()) return
            const groups = []
            for (const mutation of mutations) {
                for (const node of mutation.addedNodes) {
                    if (node.nodeType !== Node.ELEMENT_NODE) continue
                    if (node.parentElement?.matches('.fi-chat-answer')) {
                        groups.push([node])
                        continue
                    }
                    const answers = node.matches('.fi-chat-answer') ? [node] : node.querySelectorAll('.fi-chat-answer')
                    for (const answer of answers) groups.push([...answer.children])
                }
            }
            if (groups.length === 0) return
            const streamed = handoff
            handoff = null
            let delay = 0
            for (const parts of groups) {
                for (const part of parts) {
                    if (part.matches('.fi-chat-md')) {
                        if (! streamed?.text) delay += this.typeIn(part)
                        continue
                    }
                    if (streamed?.tools && part.matches('.fi-chat-timeline')) continue
                    part.style.animationDelay = delay + 'ms'
                    part.classList.add('fi-chat-enter')
                    part.addEventListener('animationend', () => { part.classList.remove('fi-chat-enter'); part.style.animationDelay = '' }, { once: true })
                    delay += 70
                }
            }
        },
    }
}
