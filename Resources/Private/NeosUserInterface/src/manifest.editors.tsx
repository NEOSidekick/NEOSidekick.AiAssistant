// @ts-ignore
import {TextField, TextArea} from '@neos-project/neos-ui-editors';
import FocusKeywordEditor from "./Editors/FocusKeywordEditor";
import ImageAltTextEditor from "./Editors/ImageAltTextEditor";
import ImageTitleEditor from "./Editors/ImageTitleEditor";
import MagicTextFieldEditor from "./Editors/MagicTextFieldEditor";
import MagicTextAreaEditor from "./Editors/MagicTextAreaEditor";
import {GlobalRegistry} from "@neos-project/neos-ts-interfaces";
import {SynchronousRegistry} from "@neos-project/neos-ui-extensibility";

export default (globalRegistry: GlobalRegistry, enabled: boolean) => {
    const editorsRegistry = globalRegistry.get('inspector').get<SynchronousRegistry<any>>('editors');
    if (!editorsRegistry) {
        console.warn('[NEOSidekick.AiAssistant]: Could not find inspector editors registry.');
        console.warn('[NEOSidekick.AiAssistant]: Skipping registration of InspectorEditor...');
        return;
    }
    // Initially register default editors
    editorsRegistry.set('NEOSidekick.AiAssistant/Inspector/Editors/FocusKeywordEditor', {
        component: enabled ? FocusKeywordEditor : TextField
    });
    editorsRegistry.set('NEOSidekick.AiAssistant/Inspector/Editors/ImageAltTextEditor', {
        component: enabled ? ImageAltTextEditor : TextArea
    });
    editorsRegistry.set('NEOSidekick.AiAssistant/Inspector/Editors/ImageTitleEditor', {
        component: enabled ? ImageTitleEditor : TextArea
    });
    editorsRegistry.set('NEOSidekick.AiAssistant/Inspector/Editors/MagicTextFieldEditor', {
        component: enabled ? MagicTextFieldEditor : TextField
    });
    editorsRegistry.set('NEOSidekick.AiAssistant/Inspector/Editors/MagicTextAreaEditor', {
        component: enabled ? MagicTextAreaEditor : TextArea
    });
}
