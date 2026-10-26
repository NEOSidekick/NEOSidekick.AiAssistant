/**
 * Host half of the iframe auto-height handshake; the child half is the NEOSidekick app's
 * resources/js/Composables/useIframeAutoHeight.js.
 *
 * Every iframe marked with data-neosidekick-auto-height grows and shrinks with its content, so the
 * module page scrolls as a whole instead of the frame scrolling inside it. The page in the frame
 * says "ready" once its app runs; we answer "init" (again on every load, in case the "ready" came
 * before we listened) and then size the frame to every height it reports.
 *
 * We also tell the frame which slice of it the editor sees below the fixed top bar, so its toasts
 * and dialogs open there rather than in the middle of a tall frame.
 *
 * A sibling marked data-neosidekick-auto-height-loader covers the frame until its first height
 * arrives. A page that never reports one (an error page, say) loses the loader shortly after it
 * has loaded, so it is not hidden forever.
 *
 * Loaded in the head, before the frames exist, so all listeners sit on window and document.
 */
(function () {
    var FRAME_SELECTOR = 'iframe[data-neosidekick-auto-height]';

    function frameOrigin(iframe) {
        try {
            return new URL(iframe.src).origin;
        } catch (error) {
            return null;
        }
    }

    function post(iframe, message) {
        var origin = frameOrigin(iframe);

        if (iframe.contentWindow && origin) {
            iframe.contentWindow.postMessage(message, origin);
        }
    }

    function visibleSlice(iframe) {
        var rect = iframe.getBoundingClientRect();
        var topBar = document.getElementById('neos-top-bar');
        var viewportTop = topBar ? topBar.getBoundingClientRect().bottom : 0;
        var top = Math.max(0, viewportTop - rect.top);
        var bottom = Math.min(rect.height, window.innerHeight - rect.top);

        return {top: Math.round(top), height: Math.max(0, Math.round(bottom - top))};
    }

    function sendViewport(iframe) {
        post(iframe, {eventName: 'iframe-auto-height:viewport', data: visibleSlice(iframe)});
    }

    function removeLoader(iframe) {
        var loader = iframe.parentElement.querySelector('[data-neosidekick-auto-height-loader]');

        if (loader) {
            loader.remove();
        }
    }

    function init(iframe) {
        post(iframe, {eventName: 'iframe-auto-height:init'});
        sendViewport(iframe);
    }

    window.addEventListener('message', function (event) {
        var eventName = event.data && event.data.eventName;

        document.querySelectorAll(FRAME_SELECTOR).forEach(function (iframe) {
            if (event.source !== iframe.contentWindow || event.origin !== frameOrigin(iframe)) {
                return;
            }

            if (eventName === 'iframe-auto-height:ready') {
                init(iframe);
            }

            if (eventName === 'iframe-auto-height:resize' && event.data.data && typeof event.data.data.height === 'number') {
                iframe.style.height = event.data.data.height + 'px';
                removeLoader(iframe);
                sendViewport(iframe);
            }
        });
    });

    // A frame's load event does not bubble, but it can be caught on its way down
    document.addEventListener('load', function (event) {
        if (event.target.matches && event.target.matches(FRAME_SELECTOR)) {
            var iframe = event.target;

            init(iframe);
            window.setTimeout(function () {
                removeLoader(iframe);
            }, 2000);
        }
    }, true);

    var viewportUpdateScheduled = false;

    function scheduleViewportUpdate() {
        if (viewportUpdateScheduled) {
            return;
        }

        viewportUpdateScheduled = true;
        window.requestAnimationFrame(function () {
            viewportUpdateScheduled = false;
            document.querySelectorAll(FRAME_SELECTOR).forEach(sendViewport);
        });
    }

    window.addEventListener('scroll', scheduleViewportUpdate, {capture: true, passive: true});
    window.addEventListener('resize', scheduleViewportUpdate);
})();
