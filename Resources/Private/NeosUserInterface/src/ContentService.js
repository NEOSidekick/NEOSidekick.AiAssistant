export default class ContentService {
    constructor() {
    }
    getGuestFrameDocumentHtml = () => {
        const guestFrame = document.getElementsByName('neos-content-main')[0];
        // @ts-ignore
        const guestFrameDocument = guestFrame?.contentDocument;
        return guestFrameDocument?.body?.innerHTML;
    }
}
