CXX = g++
CXXFLAGS = -Wall -Wextra -O2 -std=c++17
LIBS = -lpcap -lmysqlclient

TARGET = collector
SRC = collector.cpp

all: $(TARGET)

$(TARGET): $(SRC)
	$(CXX) $(CXXFLAGS) -o $(TARGET) $(SRC) $(LIBS)

clean:
	rm -f $(TARGET)

.PHONY: all clean
