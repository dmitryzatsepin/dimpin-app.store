// frontend/src/hooks/useFieldMappings.ts
import { useState, useEffect, useCallback } from 'react';
import type { ProcessField } from '../types';

export type FieldMappings = Record<string, string | null>;

export interface FieldMappingsResult {
  fieldMappings: FieldMappings;
  handleMappingChange: (smartProcessFieldId: string, fileColumnHeader: string | null) => void;
  clearMappings: () => void;
}

export const useFieldMappings = (
  fields: ProcessField[] | null,
  fileHeaders: string[]
): FieldMappingsResult => {
  const [fieldMappings, setFieldMappings] = useState<FieldMappings>({});

  const handleMappingChange = useCallback((smartProcessFieldId: string, fileColumnHeader: string | null) => {
    setFieldMappings(prevMappings => ({
      ...prevMappings,
      [smartProcessFieldId]: fileColumnHeader,
    }));
  }, []);

  const clearMappings = useCallback(() => {
    setFieldMappings({});
  }, []);

  useEffect(() => {
    if (fields && fields.length > 0 && fileHeaders.length > 0) {
      const autoMappings: FieldMappings = {};
      fields.forEach(field => {
        if (field.isReadOnly) return; 
        let matchedHeader = fileHeaders.find(
          header => header.toLowerCase() === field.title.toLowerCase()
        );
        if (!matchedHeader) {
          matchedHeader = fileHeaders.find(
            header => header.toLowerCase() === field.id.toLowerCase()
          );
        }
        autoMappings[field.id] = matchedHeader || null;
      });
      setFieldMappings(autoMappings);
    } else {
      setFieldMappings({}); 
    }
  }, [fields, fileHeaders]); 

  return { fieldMappings, handleMappingChange, clearMappings };
};