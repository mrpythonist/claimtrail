"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { useRouter } from "next/navigation";
import {
  apiFetch,
  clearSession,
  getStoredOrgId,
  getToken,
  setStoredOrgId,
  setToken,
} from "./api";
import type { Organization, User } from "./types";

interface AuthState {
  user: User | null;
  organizations: Organization[];
  currentOrg: Organization | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (name: string, email: string, password: string, password_confirmation: string) => Promise<void>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
  selectOrganization: (orgId: string) => void;
  createOrganization: (name: string, timezone: string) => Promise<Organization>;
}

const AuthContext = createContext<AuthState | null>(null);

export function useAuth(): AuthState {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error("useAuth must be used inside AuthProvider");
  }
  return context;
}

function pickCurrentOrg(orgs: Organization[]): Organization | null {
  if (orgs.length === 0) return null;
  const stored = getStoredOrgId();
  return orgs.find((org) => org.id === stored) ?? orgs[0];
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const router = useRouter();
  const [user, setUser] = useState<User | null>(null);
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [currentOrg, setCurrentOrg] = useState<Organization | null>(null);
  const [loading, setLoading] = useState(true);

  const applyOrgs = useCallback((orgs: Organization[]) => {
    setOrganizations(orgs);
    const current = pickCurrentOrg(orgs);
    setCurrentOrg(current);
    setStoredOrgId(current?.id ?? null);
  }, []);

  const refreshUser = useCallback(async () => {
    if (!getToken()) {
      setUser(null);
      setOrganizations([]);
      setCurrentOrg(null);
      setLoading(false);
      return;
    }
    try {
      const data = await apiFetch<{ user: User }>("/auth/me", {
        withOrg: false,
        redirectOnUnauthorized: false,
      });
      setUser(data.user);
      applyOrgs(data.user.organizations ?? []);
    } catch {
      clearSession();
      setUser(null);
      setOrganizations([]);
      setCurrentOrg(null);
    } finally {
      setLoading(false);
    }
  }, [applyOrgs]);

  useEffect(() => {
    // Session restore on mount: syncs React state with the stored token
    // and the API. This is the external-system sync case effects exist
    // for; the state updates happen inside refreshUser's async flow.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refreshUser();
  }, [refreshUser]);

  const login = useCallback(
    async (email: string, password: string) => {
      const data = await apiFetch<{ user: User; token: string }>("/auth/login", {
        method: "POST",
        body: { email, password },
        withOrg: false,
        redirectOnUnauthorized: false,
      });
      setToken(data.token);
      setUser(data.user);
      await refreshUser();
    },
    [refreshUser],
  );

  const register = useCallback(
    async (name: string, email: string, password: string, password_confirmation: string) => {
      const data = await apiFetch<{ user: User; token: string }>("/auth/register", {
        method: "POST",
        body: { name, email, password, password_confirmation },
        withOrg: false,
        redirectOnUnauthorized: false,
      });
      setToken(data.token);
      setUser(data.user);
      await refreshUser();
    },
    [refreshUser],
  );

  const logout = useCallback(async () => {
    try {
      await apiFetch("/auth/logout", {
        method: "POST",
        withOrg: false,
        redirectOnUnauthorized: false,
      });
    } catch {
      // Logging out locally even if the server call fails.
    } finally {
      clearSession();
      setUser(null);
      setOrganizations([]);
      setCurrentOrg(null);
      router.push("/login");
    }
  }, [router]);

  const selectOrganization = useCallback(
    (orgId: string) => {
      const org = organizations.find((candidate) => candidate.id === orgId);
      if (!org) return;
      setCurrentOrg(org);
      setStoredOrgId(org.id);
    },
    [organizations],
  );

  const createOrganization = useCallback(
    async (name: string, timezone: string) => {
      const data = await apiFetch<{ data: Organization }>("/organizations", {
        method: "POST",
        body: { name, timezone },
        withOrg: false,
      });
      const updated = [...organizations, data.data];
      applyOrgs(updated);
      setCurrentOrg(data.data);
      setStoredOrgId(data.data.id);
      return data.data;
    },
    [applyOrgs, organizations],
  );

  const value = useMemo<AuthState>(
    () => ({
      user,
      organizations,
      currentOrg,
      loading,
      login,
      register,
      logout,
      refreshUser,
      selectOrganization,
      createOrganization,
    }),
    [
      user,
      organizations,
      currentOrg,
      loading,
      login,
      register,
      logout,
      refreshUser,
      selectOrganization,
      createOrganization,
    ],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
