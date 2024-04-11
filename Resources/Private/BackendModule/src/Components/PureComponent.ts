import {PureComponent as OriginalPureComponent} from "react";
import TranslationService from "../Service/TranslationService";
export default abstract class PureComponent extends OriginalPureComponent {
    protected readonly translationService: TranslationService;

    protected constructor(props) {
        super(props);
        this.translationService = TranslationService.getInstance()
    }
}
