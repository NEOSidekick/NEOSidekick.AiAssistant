// @ts-ignore
import manifest from "@neos-project/neos-ui-extensibility";
// @ts-ignore
import { IconButton, Headline } from "@neos-project/react-ui-components";
// @ts-ignore
import {selectors} from '@neos-project/neos-ui-redux-store';
import * as React from 'react';
import { useSelector } from 'react-redux';
import "./style.css";

manifest("CodeQ.WritingAssistant", {}, (globalRegistry, { store, frontendConfiguration }) => {	
	const containerRegistry = globalRegistry.get('containers');
	const App = containerRegistry.get('App');

	const WrappedApp = (props: Record<string, unknown>) => {
		const [isOpen, setOpen] = React.useState(true);

		const activeContentDimensions = useSelector(selectors.CR.ContentDimensions.active);
		const interfaceLanguage = useSelector((state) => state?.user?.preferences?.interfaceLanguage);

		console.log({
			activeContentDimensions,
			interfaceLanguage
		});
		// exapmle: activeContentDimensions.language[0]

		return <div className="codeQ_appWrapper">
			<App {...props} />
			<div className={`codeQ_sideBar ${isOpen ? "codeQ_sideBar--open" : ""}`}>
				<div className="codeQ_sideBar__title">
					{isOpen && <Headline className="codeQ_sideBar__title--headline">Writing Assistant</Headline>}
					<IconButton icon={isOpen ? "chevron-circle-right" : "chevron-circle-left"} onClick={() => setOpen(!isOpen)} />
				</div>
				<iframe className="codeQ_sideBar__frame" src={"http://example.com"} />
			</div>
		</div>
	}
	containerRegistry.set('App', WrappedApp);
});
