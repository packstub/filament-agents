// The chat page's client side. A question is handed to the server the moment it is sent (an
// optimistic bubble until the transcript shows it); the answer is produced by a queued job and
// this component polls the turn endpoint for it — fast while a turn runs, slowly while the page
// is open, so a reload, a second tab or a chat reopened mid-answer all show the same thing.
// Questions typed while an answer runs wait on the server, per conversation. ↑ in an empty
// composer pulls the last waiting question back for editing — or the last one sent.
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
        turn: null,
        interval: 600,
        live: { status: '', html: '' },
        version: null,
        editing: false,
        stopping: false,
        timer: null,
        clearOnSync: false,

        get busy() {
            return this.turn !== null || this.sending !== null || this.outbox.length > 0
        },

        init() {
            self = this

            // Per-render values come from the state element (see the page): x-data itself stays constant.
            const state = this.$refs.state.dataset
            this.pollUrl = state.poll || null
            this.turn = state.active || null
            this.interval = parseInt(state.interval) || 600

            // Keep the end of the transcript in view while it grows, unless the person scrolled up to read.
            new MutationObserver(() => this.follow()).observe(this.$refs.transcript, { childList: true, subtree: true, characterData: true })
            window.addEventListener('scroll', () => { this.atBottom = this.nearBottom() }, { passive: true })
            document.addEventListener('visibilitychange', () => { if (! document.hidden) this.schedule(0) })

            // The server-rendered transcript takes over from the optimistic bubble (and from the streamed answer once
            // it is stored) in the same DOM update as the morph.
            const root = this.$root.closest('[wire\\:id]')
            Livewire.interceptMessage(({ message, onSuccess }) => {
                if (message.component.el !== root) return
                onSuccess(({ onSync }) => onSync(() => {
                    this.sending = null
                    if (this.clearOnSync) {
                        this.clearOnSync = false
                        this.live = { status: '', html: '' }
                    }
                }))
            })

            this.autosize()
            if (state.autoSend && state.prompt.trim() !== '') this.enqueue(state.prompt.trim())
            this.schedule(0)
        },

        submit() {
            const text = this.$refs.input.value.trim()
            if (text === '') return
            this.$refs.input.value = ''
            this.autosize()
            this.editing ? this.resend(text) : this.enqueue(text)
        },

        enqueue(text) {
            this.outbox.push({ id: ++this.sequence, text })
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
                self.started(await self.$wire.send(next.text))
            } finally {
                self.sending = null
                self.pumping = false
                setTimeout(() => self.pump(), 0)
            }
        },

        // The server told us a turn was queued: poll it (a new chat also tells us where).
        started(result) {
            if (! result) return
            if (result.poll) this.pollUrl = result.poll
            if (result.active && this.turn === null) {
                this.turn = result.turn
                this.live = { status: '', html: '' }
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

        async retry() {
            this.started(await this.$wire.retry())
        },

        async decide(id, approve) {
            this.started(await this.$wire.decide(id, approve))
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
            const [queued] = this.outbox.splice(index, 1, ...(typed === '' ? [] : [{ id: ++this.sequence, text: typed }]))
            this.draft(queued.text)
        },

        remove(id) {
            this.outbox = this.outbox.filter((m) => m.id !== id)
        },

        schedule(delay) {
            clearTimeout(self.timer)
            if (! self.pollUrl) return
            self.timer = setTimeout(() => self.poll(), delay)
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
            this.schedule(this.turn !== null ? this.interval : 4000)
        },

        apply(data) {
            const hadTurn = this.turn !== null
            if (data.active) {
                this.turn = data.active.id
                this.live = { status: data.active.statusText ?? '', html: data.active.html ?? '' }
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
