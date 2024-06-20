import React from 'react';
import TranslationService from "../Service/TranslationService";

const ProgressBar = ({ translationService, currentPage, totalPages }: ProgressBarProps) => {
    const progress = (currentPage / totalPages) * 100;

    const progressBarStyle = {
        height: '1.5rem',
        backgroundColor: 'lightgrey',
        borderRadius: '5px',
        overflow: 'hidden',
        display: 'flex',
        color: 'black',
        fontWeight: 'bold',
        marginBottom: '1rem',
    };

    const progressStyle = {
        minWidth: `${progress}%`,
        maxWidth: '100%',
        backgroundColor: 'green',
        height: '100%',
        color: '#FFFFFF',
        lineHeight: '1.5rem',
        textAlign: 'center',
        padding: '0 0.5rem',
    };

    return (
        <div style={progressBarStyle}>
            <div style={progressStyle}>
                {translationService.translate('NEOSidekick.AiAssistant:Main:progressBarLabel', `Page ${currentPage} of ${totalPages}`, {0: currentPage, 1: totalPages})}
            </div>
        </div>
    );
};

export default ProgressBar;

interface ProgressBarProps {
    translationService: TranslationService;
    currentPage: number;
    totalPages: number;
}
