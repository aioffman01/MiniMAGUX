# MiniMAGUX Network Traffic Monitor

Rocky Linux 환경에서 동작하는 고성능 실시간 네트워크 트래픽 모니터링 시스템입니다. C++ 기반의 수집기와 Manticore Search DB, 그리고 PHP 웹 대시보드로 구성되어 있습니다.

---

## 📂 프로젝트 구조

```text
MiniMAGUX/
├── backend/
│   ├── src/
│   │   └── collector.cpp          # C++ 패킷 수집기 코어 (생산자-소비자 패턴)
│   ├── Makefile                   # C++ 빌드 Makefile (결과물은 bin/ 폴더에 빌드)
│   ├── collector.cfg              # 수집기 설정 파일 (인터페이스, CSV 경로, DB 여부 등)
│   ├── manticore.conf             # Manticore Search SQL RT 테이블 데몬 설정 파일
│   └── setup-dev.backend.sh       # backend 개발 패키지 자동 설치 스크립트
├── frontend/
│   ├── db.php                     # Manticore SQL 연결을 위한 PDO 헬퍼
│   ├── index.php                  # 실시간 트래픽 통계 대시보드 (Chart.js 연동)
│   ├── search.php                 # 패킷 다이나믹 검색 및 모달 상세보기
│   └── csv_viewer.php             # 완료된 시간별 CSV 로그 조회기
├── deploy.sh                      # (로컬 단말용) 원격 리눅스 서버 배포/빌드 스크립트
├── remote-test.sh                 # (원격 서버용) 일괄 서비스 검증 및 테스트 스크립트
└── README.md                      # 프로젝트 빌드 및 실행 안내서
```

---

## 🛠️ 빌드 조건 및 필요 의존성

수집기 빌드 및 실행을 위해 **Rocky Linux 8 / 9** 계열 서버와 아래의 패키지들이 필요합니다.

### 1. 개발 및 빌드 환경 요구사항
- **컴파일러**: C++17 표준을 지원하는 `g++` (gcc-c++ 8.0 이상)
- **도구**: `make` 빌드 유틸리티
- **패킷 수집 라이브러리**: `libpcap-devel` (Packet Capture library)
- **데이터베이스 라이브러리**: `mariadb-devel` 또는 `mysql-devel` (Manticore MySQL 프로토콜 연동을 위한 mysqlclient 개발 헤더)

### 2. 실행 의존성 (런타임)
- **Manticore Search Engine**: 실시간 패킷 로그 인덱싱 및 조회용 (SQL 포트 9306 개방 필요)
- **PHP**: 웹 프론트엔드 대시보드 구동용 (PHP 7.4 이상 권장)

---

## 🚀 설치 및 빌드 방법

### 1단계: 빌드 환경 및 패키지 설치
`backend` 폴더 내부의 자동화 스크립트를 관리자 권한(`sudo`)으로 실행하여 필요한 빌드 패키지들과 Manticore 레포지토리를 원클릭 설치합니다.
```bash
cd backend
chmod +x setup-dev.backend.sh
sudo ./setup-dev.backend.sh
```

### 2단계: C++ 수집기 컴파일
`backend` 디렉토리 내에서 `make` 명령어를 실행합니다.
```bash
make
```
- 컴파일이 완료되면 `backend/bin/` 디렉토리가 생성되고, 그 안에 **`collector` 실행 파일, `collector.cfg`, `setup-dev.backend.sh`**가 자동으로 복사 및 배치됩니다.

### 3단계: 설정 파일 수정
`backend/bin/collector.cfg` 설정 파일을 필요에 맞게 수정합니다.
```ini
csv_dir = ./csv_logs        # CSV 저장 폴더 경로
interface = eth0            # 감시할 네트워크 카드 인터페이스 이름 (또는 any)
use_manticore = true        # Manticore Search 실시간 저장 사용 여부 (true/false)
manticore_host = 127.0.0.1  # Manticore Search SQL 호스트 IP
manticore_port = 9306       # Manticore Search SQL 포트 (기본 9306)
```

---

## 🏃 실행 및 테스트 방법

### 1. Manticore Search 시작 (DB 사용 시)
설치가 완료되었다면 서비스를 기동해 줍니다. 수집기가 실행될 때 `packets` RT 테이블 스키마가 없는 경우 자동으로 감지하여 동적 생성하므로 스키마를 수동으로 만드실 필요가 없습니다.
```bash
sudo systemctl start manticore
```

### 2. 백엔드 수집기 데몬 기동 및 종료
반드시 패킷 캡처를 위해 **관리자 권한(`sudo`)**으로 빌드된 바이너리를 실행해야 합니다. 실행 시 자동으로 백그라운드 데몬화됩니다.
```bash
cd backend/bin

# 수집 데몬 기동 (설정파일의 인터페이스 사용)
sudo ./collector

# 다른 인터페이스로 직접 오버라이드하여 기동할 경우 (예: eth1)
sudo ./collector eth1

# 수집 데몬 정상 종료 (메모리 버퍼의 패킷을 모두 디스크에 안전하게 flush 후 종료됨)
sudo ./collector -kill
```

### 3. 프론트엔드 웹 모니터링 대시보드 구동
`frontend` 폴더 내부에서 초경량 PHP 내장 웹 서버를 띄워 관제 페이지에 접속합니다.
```bash
cd frontend
php -S 0.0.0.0:8000
```
웹 브라우저를 통해 `http://<RockyLinux-IP>:8000`에 접속하여 실시간 대시보드 관제를 시작합니다.

---

## 📱 iOS 기반 원격 배포 및 테스트 자동화

Blink Shell, Termius 등 iOS 환경에서 원격 Linux 서버를 제어할 때 아래의 통합 스크립트를 사용하면 편리합니다.

1. **`deploy.sh` (로컬 기기 실행)**:
   상단 원격 접속 정보를 수정하고 기동하면 로컬 코드(Windows/Mac/iOS Local)를 원격 서버로 전송(`rsync`)한 뒤 바로 원격 컴파일 빌드를 트리거합니다.
   ```bash
   ./deploy.sh
   ```
2. **`remote-test.sh` (원격 서버 실행)**:
   빌드 완료 후 원격 서버 내부에서 Manticore 체크, 수집기 기동, 테스트 패킷 강제 유발(`ping 8.8.8.8`), CSV 로그 및 DB 적재 통계 자동 검증을 순차적으로 수행하고 데몬을 자동 안전 종료합니다.
   ```bash
   ./remote-test.sh
   ```
