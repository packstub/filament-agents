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
            new MutationObserver(() => { this.decorate(); this.follow() }).observe(this.$refs.transcript, { childList: true, subtree: true, characterData: true })
            window.addEventListener('scroll', () => { this.atBottom = this.nearBottom() }, { passive: true })
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
                        this.live = { status: '', html: '', tools: [] }
                    }
                    this.syncTitle()
                }))
            })

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
            this.$nextTick(() => this.scrollToBottom())
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

        async retry() {
            this.started(await this.$wire.retry())
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
                // The stored transcript replaces the streamed answer in the same DOM update (onSync above).
                this.clearOnSync = true
                self.$wire.$refresh()
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

        nearBottom() {
            return window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 160
        },

        follow() {
            if (this.atBottom) this.scrollToBottom()
        },

        scrollToBottom(smooth = false) {
            window.scrollTo({ top: document.documentElement.scrollHeight, behavior: smooth ? 'smooth' : 'auto' })
            this.atBottom = true
        },
    }
}
