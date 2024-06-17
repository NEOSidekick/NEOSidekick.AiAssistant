import {PureComponent as OriginalPureComponent} from "react";
import TranslationService from "../Service/TranslationService";
export default abstract class PureComponent<P = {}, S = {}, SS = any> extends OriginalPureComponent<P, S, SS> {
    protected readonly translationService: TranslationService;

    protected constructor(props) {
        super(props);
        this.translationService = TranslationService.getInstance()
    }
}
