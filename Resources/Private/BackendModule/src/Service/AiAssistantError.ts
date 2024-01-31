export default class AiAssistantError extends Error {
    public readonly code: string
    public readonly severity: string = 'error'
    public readonly externalMessage: string
    constructor(message: string, code: string, externalMessage: string) {
        super(message)
        this.code = code
        this.externalMessage = externalMessage
    }
}
