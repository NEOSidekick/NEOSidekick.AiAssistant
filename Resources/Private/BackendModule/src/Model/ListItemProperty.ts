export interface ListItemProperty {
    propertyName: string,
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

export interface PropertySchema {
    validation?: {
        [key: string]: any
    }
    ui: {
        label: string,
        inspector: {
            editor: string,
            editorOptions: {
                placeholder?: string
                module?: string
                arguments?: {
                    [key: string]: any
                }
            }
        }
    }
}
