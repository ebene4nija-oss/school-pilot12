'use client';

import React, { useState } from 'react';
import Sidebar from '@/components/Sidebar';

export default function LibraryPage() {
  const [books] = useState([
    { id: 1, title: 'Things Fall Apart', author: 'Chinua Achebe', isbn: '978-0385474542', barcode: 'LIB-BK-001', copies: 12, available: 8 },
    { id: 2, title: 'New General Mathematics JSS 1', author: 'M.F. Macrae', isbn: '978-0582446960', barcode: 'LIB-BK-002', copies: 25, available: 20 },
  ]);

  const [scanBarcode, setScanBarcode] = useState('');
  const [scannedResult, setScannedResult] = useState<any>(null);

  const handleBarcodeScan = (e: React.FormEvent) => {
    e.preventDefault();
    const found = books.find((b) => b.barcode.toLowerCase() === scanBarcode.trim().toLowerCase());
    setScannedResult(found || 'NOT_FOUND');
  };

  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <div className="flex items-center space-x-2">
              <span className="px-2 py-0.5 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded text-xs font-bold uppercase">
                Hardware-Free Barcode Camera Scanner
              </span>
            </div>
            <h1 className="text-2xl font-bold text-white mt-1">Digital School Library</h1>
            <p className="text-slate-400 text-sm">Catalog, borrowing tracking, and phone camera barcode scanning</p>
          </div>
          <button className="px-4 py-2.5 bg-emerald-500 hover:bg-emerald-600 font-bold text-slate-950 rounded-lg text-sm transition">
            + Catalog New Book
          </button>
        </div>

        {/* Camera Barcode Lookup Box */}
        <div className="mb-8 bg-slate-900 border border-slate-800 p-6 rounded-xl shadow-lg">
          <h2 className="text-lg font-bold text-white mb-2">📷 Phone Camera Barcode Lookup</h2>
          <p className="text-xs text-slate-400 mb-4">Scan printed book barcode using device camera or barcode query</p>

          <form onSubmit={handleBarcodeScan} className="flex gap-4">
            <input
              type="text"
              value={scanBarcode}
              onChange={(e) => setScanBarcode(e.target.value)}
              placeholder="Scan or enter barcode (e.g. LIB-BK-001)"
              className="flex-1 px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none font-mono"
            />
            <button
              type="submit"
              className="px-6 py-2.5 bg-emerald-500 hover:bg-emerald-600 font-bold text-slate-950 rounded-lg text-sm transition"
            >
              Lookup Book
            </button>
          </form>

          {scannedResult === 'NOT_FOUND' && (
            <div className="mt-4 p-3 bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs rounded-lg">
              ❌ Book barcode not found in school library catalog.
            </div>
          )}

          {scannedResult && scannedResult !== 'NOT_FOUND' && (
            <div className="mt-4 p-4 bg-emerald-500/10 border border-emerald-500/20 text-slate-200 text-sm rounded-lg flex justify-between items-center">
              <div>
                <h3 className="font-bold text-white">{scannedResult.title}</h3>
                <p className="text-xs text-slate-400">Author: {scannedResult.author} | Barcode: {scannedResult.barcode}</p>
              </div>
              <span className="px-3 py-1 bg-emerald-500 text-slate-950 font-bold text-xs rounded-md">
                {scannedResult.available} Copies Available
              </span>
            </div>
          )}
        </div>

        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
          <div className="p-4 border-b border-slate-800">
            <h3 className="font-semibold text-white">Library Catalog Inventory</h3>
          </div>
          <table className="w-full text-left text-sm text-slate-300">
            <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
              <tr>
                <th className="p-3.5">Barcode</th>
                <th className="p-3.5">Book Title</th>
                <th className="p-3.5">Author</th>
                <th className="p-3.5">ISBN</th>
                <th className="p-3.5">Available / Total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {books.map((book) => (
                <tr key={book.id} className="hover:bg-slate-800/50 transition">
                  <td className="p-3.5 font-mono text-emerald-400 text-xs">{book.barcode}</td>
                  <td className="p-3.5 font-medium text-white">{book.title}</td>
                  <td className="p-3.5">{book.author}</td>
                  <td className="p-3.5 font-mono text-xs">{book.isbn}</td>
                  <td className="p-3.5 font-bold text-emerald-400">{book.available} / {book.copies}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </main>
    </div>
  );
}
