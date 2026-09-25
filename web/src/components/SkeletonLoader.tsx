import React from 'react';

export function SkeletonLoader({ rows = 4 }: { rows?: number }) {
  return (
    <div className="w-full space-y-3 animate-pulse p-4 bg-surface-sunken border rounded-lg">
      <div className="h-6 bg-surface-raised rounded w-1/3 mb-4"></div>
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="h-4 bg-surface-raised rounded w-full"></div>
      ))}
    </div>
  );
}
