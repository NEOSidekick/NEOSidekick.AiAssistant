// @ts-ignore
import {TextField, TextArea} from '@neos-project/neos-ui-editors';
import MagicTextFieldEditor from "./Editors/MagicTextFieldEditor";
import MagicTextAreaEditor from "./Editors/MagicTextAreaEditor";
import {GlobalRegistry} from "@neos-project/neos-ts-interfaces";

export default (globalRegistry: GlobalRegistry, enabled: boolean) => {
    const editorsRegistry = globalRegistry.get('inspector').get('editors');
    // Initially register default editors
    editorsRegistry.set('NEOSidekick.AiAssistant/Inspector/Editors/MagicTextFieldEditor', {
        component: enabled ? MagicTextFieldEditor : TextField
    });
    editorsRegistry.set('NEOSidekick.AiAssistant/Inspector/Editors/MagicTextAreaEditor', {
        component: enabled ? MagicTextAreaEditor : TextArea
    });
}
