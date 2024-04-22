export default class PropertiesCollection {
    public properties: object[]

    constructor(properties: object[]) {
        this.properties = properties
    }


}

export interface PropertyInterface {
    propertyName: string,
    initialValue: string,
    currentValue: string,
    state: PropertyState
}

export enum PropertyState {
    Initial = 'initial',
    Generating= 'generating',
    AiGenerated = 'ai-generated',
    UserManipulated = 'user-manipulated'
}
