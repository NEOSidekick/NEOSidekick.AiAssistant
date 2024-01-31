import AssetDtoInterface from "../Model/AssetDtoInterface";

export default interface StateInterface {
    app: {
        loading: boolean,
        persisting: boolean,
    },
    assets: {
        busy: boolean,
        items: AssetDtoInterface[]
    }
}
