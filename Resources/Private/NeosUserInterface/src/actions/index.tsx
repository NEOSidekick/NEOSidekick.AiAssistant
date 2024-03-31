import produce from 'immer';
import { action, ActionType } from 'typesafe-actions';

export enum actionTypes {
    OPEN = '@neosidekick/ai-assistant/UI/ModifyDialog/OPEN',
    CANCEL = '@neosidekick/ai-assistant/UI/ModifyDialog/CANCEL',
    APPLY = '@neosidekick/ai-assistant/UI/ModifyDialog/APPLY',
}

const openModal = (fullText:string, selectedText: string) => action(actionTypes.OPEN, {selectedText, fullText});
const cancelModal = () => action(actionTypes.CANCEL);
const applyModal = (newText: string) => action(actionTypes.APPLY, {newText});

export const actions = {
    openModal,
    cancelModal,
    applyModal,
};

export type Action = ActionType<typeof actions>;
export const reducer = (state: any, action: Action) => produce(state, (draft: any) => {
    switch (action.type) {
        // @ts-ignore
        case '@neos/neos-ui/System/INIT': {
            draft.plugins.NEOSidekick = {};
            draft.plugins.NEOSidekick.AiAssistant = {};
            draft.plugins.NEOSidekick.AiAssistant.isModalOpen = false;
            break;
        }
        case actionTypes.OPEN: {
            draft.plugins.NEOSidekick.AiAssistant.isModalOpen = true;
            draft.plugins.NEOSidekick.AiAssistant.fullText = action.payload.fullText;
            draft.plugins.NEOSidekick.AiAssistant.selectedText = action.payload.selectedText;
            break;
        }
        case actionTypes.CANCEL: {
            draft.plugins.NEOSidekick.AiAssistant.isModalOpen = false;
            break;
        }
        case actionTypes.APPLY: {
            draft.plugins.NEOSidekick.AiAssistant.isModalOpen = false;
            break;
        }
    }
});

export const selectors = {
    isModalOpen: (state: any) => state.plugins?.NEOSidekick?.AiAssistant?.isModalOpen,
    fullText: (state: any) => state.plugins?.NEOSidekick?.AiAssistant?.fullText,
    selectedText: (state: any) => state.plugins?.NEOSidekick?.AiAssistant?.selectedText,
};
