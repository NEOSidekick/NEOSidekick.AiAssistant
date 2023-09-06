export default class AiAssistantError extends Error {
    public readonly code: string;
    public readonly severity: string = 'error';
    constructor(message: string, code: string) {
        super(message);
        this.code = code
    }
}
