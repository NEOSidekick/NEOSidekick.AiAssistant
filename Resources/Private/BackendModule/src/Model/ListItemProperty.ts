export interface ListItemProperty {
    propertyName: string,
    aliasPropertyName?: string,
    initialValue: string,
    currentValue: string,
    state: ListItemPropertyState
}

export enum ListItemPropertyState {
    Initial = 'initial',
    Generating= 'generating',
    AiGenerated = 'ai-generated',
    UserManipulated = 'user-manipulated'
}

export interface EditorOptions {
    placeholder?: string
    module?: string
    arguments?: {
        [key: string]: any
    },
    imagePropertyName?: string,
    fallbackAssetPropertyName?: string,
}

export interface PropertySchema {
    validation?: {
        [key: string]: any
    }
    ui: {
        label: string,
        inspector: {
            editor: string,
            editorOptions: EditorOptions
        }
    }
}
