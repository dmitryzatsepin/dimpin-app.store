// frontend/src/hooks/useProcessFields.ts
import { useState, useEffect } from 'react';
import axios from 'axios';
import type { ProcessField, ApiResponse } from '../types';

export const useProcessFields = (selectedProcessId: string | null) => {
  const [fields, setFields] = useState<ProcessField[]>([]);
  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!selectedProcessId) {
      setFields([]);
      setLoading(false);
      setError(null);
      return;
    }

    const fetchFields = async () => {
      setLoading(true);
      setError(null);
      const initialData = window.bx24InitialData;
      const config = window.appConfig;

      if (!initialData?.member_id || !config?.apiBaseUrl) {
        setError('Configuration error: Initial data or API URL not found for fetching fields.');
        setLoading(false);
        return;
      }

      try {
        const apiUrl = `${config.apiBaseUrl}?action=get_smart_process_fields&entityTypeId=${selectedProcessId}&member_id=${initialData.member_id}&DOMAIN=${initialData.domain}`;
        const response = await axios.get<ApiResponse<ProcessField[]>>(apiUrl);
        if (response.data?.success && response.data.data) {
          setFields(response.data.data);
        } else {
          setError(response.data?.message || response.data?.error || 'Failed to load smart process fields.');
          setFields([]);
        }
      } catch (e: any) {
        setError(e.response?.data?.message || e.response?.data?.error || e.message || 'Network or server error occurred while fetching fields.');
        setFields([]);
      }
      finally { setLoading(false); }
    };

    fetchFields();
  }, [selectedProcessId]);

  return { fields, loadingFields: loading, fieldsError: error, setFields };
};