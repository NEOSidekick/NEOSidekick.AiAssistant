import React from "react";
import TranslationService from "../Service/TranslationService";

interface ProgressCirclesProps {
    currentPage: number;
    totalPages: number;
}

export default class ProgressCircles extends React.Component<ProgressCirclesProps> {
    constructor(props: any) {
        super(props);
    }

    private steps() {
        const completedPages = Array.from({length: this.props.currentPage - 1}, (_, i) => ({
            name: i + 1,
            status: 'complete'
        }));
        const upcomingPages = Array.from({length: this.props.totalPages - this.props.currentPage}, (_, i) => ({
            name: this.props.currentPage + i + 1,
            status: 'upcoming'
        }));
        return [
            ...completedPages,
            {
                name: this.props.currentPage,
                status: 'current'
            },
            ...upcomingPages
        ]
    }
    render() {
        const translationService = TranslationService.getInstance();
        return (
            <nav style={{display: 'flex', gap: '1.5rem', alignItems: 'center', marginBottom: '1.25rem'}}>
                <ol style={{display: 'flex', alignItems: 'center'}}>
                    {this.steps().map((step, stepIdx) => (
                        <li key={step.name}
                            style={{paddingRight: stepIdx < (this.steps().length - 1) ? '2rem' : '', position: 'relative'}}>
                            {step.status === 'complete' ? (
                                <>
                                    <div
                                        style={{
                                            position: 'absolute',
                                            inset: 0,
                                            display: 'flex',
                                            alignItems: 'center'
                                        }}>
                                        <div
                                            style={{
                                                height: '0.125rem',
                                                width: '100%',
                                                backgroundColor: '#00a338'
                                            }}/>
                                        <div
                                            style={{
                                                height: '0.125rem',
                                                width: '100%',
                                                backgroundColor: '#00a338'
                                            }}/>
                                    </div>
                                    <span
                                        style={{
                                            position: 'relative',
                                            display: 'flex',
                                            height: '2rem',
                                            width: '2rem',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            borderRadius: '9999px',
                                            backgroundColor: '#00a338'
                                        }}>
                                    <svg style={{height: '1.25rem', width: '1.25rem', color: '#ffffff'}}
                                         viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd"
                                              d="M19.916 4.626a.75.75 0 01.208 1.04l-9 13.5a.75.75 0 01-1.154.114l-6-6a.75.75 0 011.06-1.06l5.353 5.353 8.493-12.739a.75.75 0 011.04-.208z"
                                              clip-rule="evenodd"/>
                                    </svg>
                                </span>
                                </>
                            ) : step.status === 'current' ? (
                                <>
                                    <div style={{position: 'absolute', inset: 0, display: 'flex', alignItems: 'center'}}>
                                        <div style={{height: '0.125rem', width: '100%', background: 'gray'}}  />
                                    </div>
                                    <span style={{
                                        position: 'relative',
                                        height: '2rem',
                                        width: '2rem',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        borderRadius: '9999px',
                                        border: '2px solid #00a338',
                                        backgroundColor: '#222'}}>
                                        <span style={{
                                            height: '0.625rem',
                                            width: '0.625rem',
                                            borderRadius: '9999px',
                                            backgroundColor: '#00a338'}}/>
                                    </span>
                                </>
                            ) : (
                                <>
                                    <div style={{position: 'absolute', inset: 0, display: 'flex', alignItems: 'center'}}>
                                        <div style={{height: '0.125rem', width: '100%', backgroundColor: 'gray'}}/>
                                    </div>
                                    <span style={{
                                        position: 'relative',
                                        display: 'flex',
                                        height: '2rem',
                                        width: '2rem',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        borderRadius: '9999px',
                                        border: '2px solid #d2d6dc',
                                        backgroundColor: '#222'}}>
                                      <span style={{
                                          height: '0.625rem',
                                          width: '0.625rem',
                                          borderRadius: '9999px',
                                          backgroundColor: 'transparent'}}/>
                                    </span>
                                </>
                            )}
                        </li>
                    ))}
                </ol>
                <span>
                    {translationService.translate('NEOSidekick.AiAssistant:Main:progressBarLabel', `Page ${this.props.currentPage} of ${this.props.totalPages}`, {0: this.props.currentPage, 1: this.props.totalPages})}
                </span>
            </nav>
        )
    }
}
