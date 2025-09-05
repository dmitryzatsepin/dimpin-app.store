// frontend/src/types.ts
export interface ApiResponse<T> {
  success: boolean;
  data?: T;
  error?: string;
  message?: string;
  details?: any;
}

export interface SmartProcess {
  id: string;
  title: string;
}

export interface ProcessField {
  id: string;
  title: string;
  type: string;
  isMultiple: boolean;
  isRequired: boolean;
  isReadOnly: boolean;
  items?: { ID: string; VALUE: string }[];
}
export interface ImportAttemptResponse { 
  success: boolean;
  message: string;
  importedCount?: number;
  updatedCount?: number;
  failedCount?: number;
  errors?: ImportApiResponseErrorItem[];
  details?: any;
  error?: string;
}

export interface ImportApiResponseErrorItem {
  itemIndexOriginal: number | string;
  errorDetails: string;
  itemData?: any;
  type?: string;
}