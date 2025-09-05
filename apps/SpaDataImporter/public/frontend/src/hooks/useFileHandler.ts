// frontend/src/hooks/useFileHandler.ts
import { useState, useCallback } from 'react';
import { MIME_TYPES, type FileWithPath as MantineOriginalFileWithPath } from '@mantine/dropzone'; // 1. Import with an alias
import * as XLSX from 'xlsx';
import Papa from 'papaparse';

// 2. Re-export the type under the desired name for other modules to use
export type { MantineOriginalFileWithPath as FileWithPath };

// Local type definitions
export type FileRow = Record<string, string | number | boolean | null>;
export type FileData = FileRow[];

export interface FileHandlerResult {
  uploadedFile: MantineOriginalFileWithPath | null; // 3. Use the alias internally
  fileHeaders: string[];
  parsingFile: boolean;
  fileError: string | null;
  handleFileDrop: (files: MantineOriginalFileWithPath[]) => void; // 3. Use the alias internally
  readFileData: () => Promise<FileData>;
  clearFile: () => void;
  setFileError: (error: string | null) => void;
}

export const useFileHandler = (): FileHandlerResult => {
  const [uploadedFile, setUploadedFile] = useState<MantineOriginalFileWithPath | null>(null); // 3. Use the alias internally
  const [fileHeaders, setFileHeaders] = useState<string[]>([]);
  const [parsingFile, setParsingFile] = useState<boolean>(false);
  const [fileError, setFileError] = useState<string | null>(null);

  const clearFile = useCallback(() => {
    setUploadedFile(null);
    setFileHeaders([]);
    setFileError(null);
    setParsingFile(false);
  }, []);

  const handleFileDrop = useCallback((files: MantineOriginalFileWithPath[]) => { // 3. Use the alias internally
    if (files.length > 0) {
      const file = files[0];
      setUploadedFile(file);
      setFileError(null);
      setParsingFile(true);
      setFileHeaders([]);

      const reader = new FileReader();
      reader.onload = (event) => {
        try {
          const fileData = event.target?.result;
          if (!fileData) {
            setFileError('Could not read file data.');
            setParsingFile(false);
            return;
          }
          let headers: string[] = [];
          if (file.type === MIME_TYPES.csv || file.name.toLowerCase().endsWith('.csv')) {
            const result = Papa.parse(fileData as string, { header: false, preview: 1, skipEmptyLines: true });
            if (result.data && result.data.length > 0) {
              headers = (result.data[0] as string[]).map(h => String(h || '').trim()).filter(h => h !== '');
            }
          } else if (
            file.type === MIME_TYPES.xls || file.type === MIME_TYPES.xlsx ||
            file.name.toLowerCase().endsWith('.xls') || file.name.toLowerCase().endsWith('.xlsx') ||
            file.type === 'application/vnd.ms-excel' ||
            file.type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
          ) {
            const workbook = XLSX.read(fileData, { type: 'array' });
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            // Get headers from the first row
            const jsonDataForHeaders: any[][] = XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: "" });
            if (jsonDataForHeaders.length > 0) {
                headers = jsonDataForHeaders[0].map(h => String(h).trim()).filter(h => h !== '');
            }
          } else {
            setFileError(`Unsupported file type: ${file.type || 'unknown'}. Please upload XLSX or CSV.`);
            setParsingFile(false);
            return;
          }
          setFileHeaders(headers.filter(h => h !== ''));
        } catch (err: any) {
          setFileError(`Error parsing file headers: ${err.message || 'Unknown error'}`);
        } finally {
          setParsingFile(false);
        }
      };
      reader.onerror = () => {
        setFileError('Error reading file for headers.');
        setParsingFile(false);
      };

      if (file.type === MIME_TYPES.csv || file.name.toLowerCase().endsWith('.csv')) {
        reader.readAsText(file);
      } else {
        reader.readAsArrayBuffer(file);
      }
    }
  }, []);

  // readFileData (с изменениями для парсинга чисел, которые мы обсуждали ранее)
  const readFileData = useCallback(async (): Promise<FileData> => {
    if (!uploadedFile) {
      throw new Error("No file uploaded to read data from.");
    }
    setFileError(null);

    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      const isCsv = uploadedFile.type === MIME_TYPES.csv || uploadedFile.name.toLowerCase().endsWith('.csv');
      reader.onload = (event) => {
        try {
          const fileBinaryData = event.target?.result;
          if (!fileBinaryData) {
            reject(new Error('Could not read file data for import.'));
            return;
          }
          let dataRows: FileRow[] = [];
          const isCsv = uploadedFile.type === MIME_TYPES.csv || uploadedFile.name.toLowerCase().endsWith('.csv');

          if (isCsv) {
            const result = Papa.parse(fileBinaryData as string, {
              header: true,
              skipEmptyLines: true,
              transformHeader: header => header.trim(),
              dynamicTyping: false, // IMPORTANT: Get strings to manually parse numbers
            });
            if (result.errors.length > 0) {
              const errorMessages = result.errors.map(e => `Row ${e.row}: ${e.message} (Code: ${e.code})`).join('; ');
              reject(new Error(`CSV parsing error(s): ${errorMessages}`)); return;
            }
            dataRows = result.data as FileData;
          } else { // For XLSX
            const workbook = XLSX.read(fileBinaryData, { type: 'array' });
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            const jsonDataRaw: any[][] = XLSX.utils.sheet_to_json(worksheet, {
              header: 1, raw: false, defval: null
            });
            if (jsonDataRaw.length > 0) {
                const headersXlsx = jsonDataRaw[0].map(h => String(h ?? '').trim());
                dataRows = jsonDataRaw.slice(1).map(rowArray => {
                    const rowObject: FileRow = {};
                    headersXlsx.forEach((header, idx) => {
                        if (header) {
                             rowObject[header] = rowArray[idx] !== null && rowArray[idx] !== undefined ? String(rowArray[idx]) : null;
                        }
                    });
                    return rowObject;
                }).filter(row => Object.values(row).some(val => val !== null && String(val).trim() !== ''));
            }
          }

          const cleanedDataRows = dataRows.map(row => {
            const cleanedRow: FileRow = {};
            for (const key in row) {
              if (Object.prototype.hasOwnProperty.call(row, key)) {
                let value = row[key];
                if (typeof value === 'string') {
                  const currencySymbolsAndThousands = /[$\€\£\₽\s]|(Dh)/gi;
                  let numericString = value.replace(currencySymbolsAndThousands, '');
                  if (/^-?\d{1,3}(,\d{3})*(\.\d+)?$/.test(numericString.trim())) {
                     numericString = numericString.replace(/,/g, '');
                  } else if (/^-?\d{1,3}(\.\d{3})*(,\d+)?$/.test(numericString.trim())) {
                     numericString = numericString.replace(/\./g, '').replace(/,/, '.');
                  }
                  const num = parseFloat(numericString);
                  if (!isNaN(num)) { value = num; }
                  // else value remains the original string if not parseable as number
                }
                cleanedRow[key] = value;
              }
            }
            return cleanedRow;
          });
          resolve(cleanedDataRows);
        } catch (err: any) {
          reject(new Error(`Error processing file data: ${err.message || 'Unknown error'}`));
        }
      };
      reader.onerror = () => { reject(new Error('Error reading file for import.')); };

      if (isCsv) {
        reader.readAsText(uploadedFile);
      } else {
        reader.readAsArrayBuffer(uploadedFile);
      }
    });
  }, [uploadedFile]);

  return {
    uploadedFile, fileHeaders, parsingFile, fileError,
    handleFileDrop, readFileData, clearFile, setFileError
  };
};