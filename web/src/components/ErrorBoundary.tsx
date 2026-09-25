'use client';

import React, { Component, ErrorInfo, ReactNode } from 'react';

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
}

interface State {
  hasError: boolean;
  error?: Error;
}

export class ErrorBoundary extends Component<Props, State> {
  public state: State = {
    hasError: false,
  };

  public static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    console.error('Uncaught Error Boundary Exception:', error, errorInfo);
  }

  public render() {
    if (this.state.hasError) {
      return (
        this.props.fallback || (
          <div className="p-6 bg-negative-soft border border-negative/20 rounded-lg text-negative my-4 text-center">
            <h2 className="text-lg font-bold mb-2">Something went wrong</h2>
            <p className="text-sm">{this.state.error?.message || 'An unexpected application error occurred.'}</p>
            <button
              onClick={() => this.setState({ hasError: false })}
              className="mt-4 px-4 py-2 bg-negative text-white rounded hover:opacity-90 text-sm font-medium transition"
            >
              Try Again
            </button>
          </div>
        )
      );
    }

    return this.props.children;
  }
}
