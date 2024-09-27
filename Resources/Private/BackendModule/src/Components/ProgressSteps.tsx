import React from 'react';

interface ProgressStepsProps {
    steps: Array<{ id: string, name: string, href: string, status: 'complete' | 'current' | 'upcoming' }>;
}

/* demo data
const steps = [
    {id: '01', name: 'Job details', href: '#', status: 'complete'},
    {id: '02', name: 'Application form', href: '#', status: 'current'},
    {id: '03', name: 'Preview', href: '#', status: 'upcoming'},
]
*/

export default function ProgressSteps({steps}: ProgressStepsProps) {
    return (
        <nav aria-label="Progress">
            <ol role="list"
                style={{border: '1px solid #3f3f3f', borderRadius: '0.375rem', display: 'flex', marginBottom: '1rem'}}>
                {steps.map((step, stepIdx) => (
                    <li key={step.name} style={{position: 'relative', display: 'flex', flex: '1 1 0%'}}>
                        {step.status === 'complete' ? (
                            <a href={step.href} style={{display: 'flex', width: '100%', alignItems: 'center'}}>
                                <span style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    padding: '1rem',
                                    fontSize: '0.875rem',
                                    fontWeight: '500'
                                }}>
                                  <span
                                      style={{
                                          display: 'flex',
                                          height: '2.5rem',
                                          width: '2.5rem',
                                          flexShrink: '0',
                                          alignItems: 'center',
                                          justifyContent: 'center',
                                          borderRadius: '9999px',
                                          backgroundColor: '#00a338'
                                      }}>
                                    <svg style={{height: '1.5rem', width: '1.5rem', color: '#ffffff'}}
                                         viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                      <path fill-rule="evenodd"
                                            d="M19.916 4.626a.75.75 0 01.208 1.04l-9 13.5a.75.75 0 01-1.154.114l-6-6a.75.75 0 011.06-1.06l5.353 5.353 8.493-12.739a.75.75 0 011.04-.208z"
                                            clip-rule="evenodd"/>
                                    </svg>
                                  </span>
                                  <span style={{
                                      marginLeft: '1rem',
                                      fontSize: '0.875rem',
                                      fontWeight: '500',
                                      color: '#00a338'
                                  }}>{step.name}</span>
                                </span>
                            </a>
                        ) : step.status === 'current' ? (
                            <a href={step.href} style={{
                                display: 'flex',
                                alignItems: 'center',
                                padding: '1rem',
                                fontSize: '0.875rem',
                                fontWeight: '500'
                            }}
                               aria-current="step">
                                <span
                                    style={{
                                        display: 'flex',
                                        height: '2.5rem',
                                        width: '2.5rem',
                                        flexShrink: '0',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        border: '2px solid #00b5ff',
                                        borderRadius: '9999px',
                                    }}>
                                  <span style={{color: '#00b5ff'}}>{step.id}</span>
                                </span>
                                <span style={{
                                    marginLeft: '1rem',
                                    fontSize: '0.875rem',
                                    fontWeight: '500',
                                    color: '#00b5ff'
                                }}>{step.name}</span>
                            </a>
                        ) : (
                            <a href={step.href} style={{display: 'flex', alignItems: 'center'}}>
                                <span style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    padding: '1rem',
                                    fontSize: '0.875rem',
                                    fontWeight: '500'
                                }}>
                                  <span
                                      style={{
                                          display: 'flex',
                                          height: '2.5rem',
                                          width: '2.5rem',
                                          flexShrink: '0',
                                          alignItems: 'center',
                                          justifyContent: 'center',
                                          border: '2px solid #3f3f3f',
                                          borderRadius: '9999px'
                                      }}>
                                    <span style={{color: '#6b7280'}}>{step.id}</span>
                                  </span>
                                  <span style={{
                                      marginLeft: '1rem',
                                      fontSize: '0.875rem',
                                      fontWeight: '500',
                                      color: '#6b7280'
                                  }}>{step.name}</span>
                                </span>
                            </a>
                        )}

                        {(stepIdx !== steps.length - 1) && (
                            <>
                                {/* Arrow separator for lg screens and up */}
                                <div style={{
                                    position: 'absolute',
                                    right: '0',
                                    top: '0',
                                    height: '100%',
                                    width: '1.25rem'
                                }} aria-hidden="true">
                                    <svg
                                        style={{height: '100%', width: '100%', color: '#3f3f3f'}}
                                        viewBox="0 0 22 80"
                                        fill="none"
                                        preserveAspectRatio="none"
                                    >
                                        <path
                                            d="M0 -2L20 40L0 82"
                                            vectorEffect="non-scaling-stroke"
                                            stroke="currentcolor"
                                            strokeLinejoin="round"
                                        />
                                    </svg>
                                </div>
                            </>
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    )
};
