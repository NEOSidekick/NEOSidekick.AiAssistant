import React from 'react';
import TranslationService from "../Service/TranslationService";

interface ProgressBarProps {
    currentPage: number;
    totalPages: number;
}

export default class ProgressBar extends React.Component<ProgressBarProps> {
    render() {
        const { currentPage, totalPages } = this.props;
        const progress = (currentPage / totalPages) * 100;
        const translationService = TranslationService.getInstance();

        const progressBarStyle = {
            height: '2.25rem',
            border: '2px solid rgb(210, 214, 220)',
            borderRadius: '9999px',
            overflow: 'hidden',
            display: 'flex',
            color: 'black',
            fontWeight: 'bold',
            marginBottom: '1rem'
        };

        const progressStyle = {
            minWidth: `${progress}%`,
            maxWidth: '100%',
            display: 'flex',
            justifyContent: 'flex-end',
            alignItems: 'center',
            gap: '0.5rem',
            backgroundColor: 'green',
            height: '100%',
            color: '#FFFFFF',
            lineHeight: '2rem',
            align: 'center',
            padding: '0 1rem',
            borderRadius: '9999px',
            boxSizing: 'border-box'
        };

        return (
            <div style={progressBarStyle}>
                <div style={progressStyle}>
                    <svg style={{height: '1.25rem', width: '1.25rem', color: '#ffffff'}}
                         viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd"
                              d="M19.916 4.626a.75.75 0 01.208 1.04l-9 13.5a.75.75 0 01-1.154.114l-6-6a.75.75 0 011.06-1.06l5.353 5.353 8.493-12.739a.75.75 0 011.04-.208z"
                              clip-rule="evenodd"/>
                    </svg>
                    <span>{translationService.translate('NEOSidekick.AiAssistant:Main:progressBarLabel', `Page ${currentPage} of ${totalPages}`, {
                        0: currentPage,
                        1: totalPages
                    })}</span>
                </div>
            </div>
        );
    }
}
