// frontend/src/hooks/useSmartProcesses.ts
import { useState, useEffect } from 'react';
import axios from 'axios';
import type { ApiResponse } from '../types'; // Предполагаем, что ApiResponse будет в types.ts

// Перенесем интерфейс SmartProcess сюда или в types.ts
export interface SmartProcess {
  id: string; // This is the numeric entityTypeId for API calls
  title: string;
}

export const useSmartProcesses = () => {
  const [smartProcesses, setSmartProcesses] = useState<SmartProcess[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchSmartProcesses = async () => {
      setLoading(true);
      setError(null);
      const initialData = window.bx24InitialData;
      const config = window.appConfig;

      if (!initialData?.member_id || !config?.apiBaseUrl) {
        setError('Configuration error: Initial data or API URL not found.');
        setLoading(false);
        return;
      }
      try {
        const apiUrl = `${config.apiBaseUrl}?action=get_smart_processes&member_id=${initialData.member_id}&DOMAIN=${initialData.domain}`;
        const response = await axios.get<ApiResponse<SmartProcess[]>>(apiUrl);
        if (response.data?.success && response.data.data) {
          setSmartProcesses(response.data.data);
        } else {
          setError(response.data?.message || response.data?.error || 'Failed to load smart process list.');
        }
      } catch (e: any) {
        setError(e.response?.data?.message || e.response?.data?.error || e.message || 'Network or server error occurred.');
      } 
      finally { setLoading(false); }
    };
    fetchSmartProcesses();
  }, []); // Пустой массив зависимостей, чтобы выполнился один раз при монтировании

  return { smartProcesses, loadingProcesses: loading, errorProcesses: error };
};