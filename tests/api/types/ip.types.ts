export interface IpSubnet {
  id: number;
  ip: string;
  mask: number;
  family: number;
  is_shared: boolean;
}

export interface IpSubnetListMeta {
  default_ipv4?: {
    address: string | null;
    assignments: unknown[];
  };
  default_ipv6?: {
    address: string | null;
    assignments: unknown[];
  };
}

export interface IpSubnetListResponse {
  data: IpSubnet[];
  meta: IpSubnetListMeta;
}

export interface AddIpSubnetRequest {
  ip: string;
  mask: number;
  is_shared?: boolean;
}

export interface AssignedIp {
  id: number;
  ip_address: string;
  ip_subnet_id: number;
  username?: string;
  user_id?: number;
}

export interface AssignIpRequest {
  username?: string;
  user_id?: number;
  ip_subnet_id: number;
  ip_address: string;
}

export interface UnassignIpRequest {
  username?: string;
  user_id?: number;
  ip_subnet_id: number;
  ip_address: string;
}
