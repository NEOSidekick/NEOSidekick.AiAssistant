import {GlobalRegistry} from "@neos-project/neos-ts-interfaces";
import RegenerateButton from "./Components/RegenerateButton";

export default (globalRegistry: GlobalRegistry) => {
    const ckEditorRegistry = globalRegistry.get('ckEditor5');
    const richtextToolbar = ckEditorRegistry.get('richtextToolbar');
    richtextToolbar.set('NEOSidekick.AiAssistant:generate', {
        component: RegenerateButton,
        isVisible: () => true,
    }, 'end');
}
