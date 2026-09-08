// The chat page's client side: the composer never locks. A question shows in the
// transcript the moment it is sent; anything typed while an answer is still
// streaming waits in a queue and goes out next, one turn at a time (Livewire
// answers one request per component at a time). ↑ in an empty composer pulls the
// last queued question back for editing — or the last one sent, to send it again.
export default function agentChat({ prompt = '', autoSend = false } = {}) {
    return {
        queue: [],
        sending: null,
        history: [],
        busy: false,
        atBottom: true,
        sequence: 0,

        init() {
            // Keep the end of the transcript in view while it grows, unless the person scrolled up to read.
            new MutationObserver(() => this.follow()).observe(this.$refs.transcript, { childList: true, subtree: true, characterData: true })
            window.addEventListener('scroll', () => { this.atBottom = this.nearBottom() }, { passive: true })

            // The server-rendered transcript takes over from the optimistic bubble in the same DOM update as the morph.
            const root = this.$root.closest('[wire\\:id]')
            Livewire.interceptMessage(({ message, onSuccess }) => {
                if (message.component.el !== root) return
                onSuccess(({ onSync }) => onSync(() => { this.sending = null }))
            })

            this.autosize()
            if (autoSend && prompt.trim() !== '') this.enqueue(prompt.trim())
        },

        submit() {
            const text = this.$refs.input.value.trim()
            if (text === '') return
            this.$refs.input.value = ''
            this.autosize()
            this.enqueue(text)
        },

        enqueue(text) {
            this.queue.push({ id: ++this.sequence, text })
            this.$nextTick(() => this.scrollToBottom())
            this.pump()
        },

        async pump() {
            if (this.busy || this.queue.length === 0) return
            this.busy = true
            const next = this.queue.shift()
            this.sending = next
            this.history.push(next.text)
            try {
                await this.$wire.send(next.text)
            } finally {
                this.sending = null
                this.busy = false
                this.pump()
            }
        },

        // ↑ in an empty composer: edit the last queued question, else recall the last one sent.
        recall(event) {
            if (this.$refs.input.value !== '') return
            const queued = this.queue.pop()
            const text = queued ? queued.text : this.history.at(-1)
            if (!text) return
            event.preventDefault()
            this.draft(text)
        },

        // Edit a queued question; whatever was being typed takes its place in the queue so nothing is lost.
        edit(id) {
            const index = this.queue.findIndex((m) => m.id === id)
            if (index === -1) return
            const typed = this.$refs.input.value.trim()
            const [queued] = this.queue.splice(index, 1, ...(typed === '' ? [] : [{ id: ++this.sequence, text: typed }]))
            this.draft(queued.text)
        },

        remove(id) {
            this.queue = this.queue.filter((m) => m.id !== id)
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
            input.style.height = Math.min(input.scrollHeight, 240) + 'px'
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
